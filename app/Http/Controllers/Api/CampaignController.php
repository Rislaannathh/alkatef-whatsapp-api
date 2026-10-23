<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListCampaignsRequest;
use App\Http\Requests\SetupCampaignRequest;
use App\Http\Resources\CampaignDetailResource;
use App\Http\Resources\CampaignResource;
use App\Jobs\ImportCampaignContacts;
use App\Models\Campaign;
use App\Models\ContactImportStatus;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CampaignController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/campaigns
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a paginated list of campaigns belonging to the authenticated
     * user's company.
     *
     * Supports optional filtering by name (search) and status.
     * Company-level isolation is always enforced server-side.
     */
    public function index(ListCampaignsRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $perPage = (int) ($request->input('per_page', 15));

        if (empty($user->company_id)) {
            return response()->json([
                'success'    => true,
                'message'    => 'Campaigns retrieved successfully.',
                'data'       => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $perPage,
                    'total'        => 0,
                    'last_page'    => 1,
                ],
            ]);
        }

        $campaigns = Campaign::query()
            ->where('company_id', $user->company_id)
            ->with(['template.templateCategory'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success'    => true,
            'message'    => 'Campaigns retrieved successfully.',
            'data'       => CampaignResource::collection($campaigns),
            'pagination' => [
                'current_page' => $campaigns->currentPage(),
                'per_page'     => $campaigns->perPage(),
                'total'        => $campaigns->total(),
                'last_page'    => $campaigns->lastPage(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/campaigns/{campaign_id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return the full details of a single campaign.
     *
     * Authorization: the campaign must belong to the authenticated user's
     * company.  A 404 is returned when the campaign does not exist OR belongs
     * to a different company — never leaking cross-company data.
     */
    public function show(string|int $campaignId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (empty($user->company_id) || ! is_numeric($campaignId)) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        $campaign = Campaign::where('id', (int) $campaignId)
            ->where('company_id', $user->company_id)
            ->with(['template.templateCategory', 'contactImportStatus'])
            ->first();

        if ($campaign === null) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Campaign retrieved successfully.',
            'data'    => new CampaignDetailResource($campaign),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/campaigns/setup
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a template, campaign, and contact-import status in one atomic
     * transaction, then dispatch the import job after the commit.
     *
     * The response is returned immediately with HTTP 201.  The frontend must
     * poll GET /api/campaigns/{id}/contact-import-status to track progress.
     */
    public function setup(SetupCampaignRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user      = $request->user();
        $companyId = $user->company_id;
        $userId    = $user->id;

        $templateData   = $request->input('template');
        $campaignData   = $request->input('campaign');
        $inputType      = $request->contactInputType();
        $importBehavior = $request->importBehavior();

        // Store the uploaded file BEFORE the transaction so we have a path to
        // reference in the dispatched job.  The file is stored in the private
        // disk (storage/app/private) which is not publicly accessible.
        $storedFilePath = null;
        if ($inputType === 'file') {

            //File upload option setup for import contacts
            $file = $request->file('contacts_file');

            if (! $file || ! $file->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The uploaded contacts file is missing or invalid.',
                ], 422);
            }

            $storedFilePath = $file->store('contact-imports', 'local');

            if ($storedFilePath === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to store the uploaded file.',
                ], 500);
            }

            Log::info('Campaign contact file stored', [
                'path' => $storedFilePath,
                'disk_root' => config('filesystems.disks.local.root'),
                'absolute_path' => Storage::disk('local')->path($storedFilePath),
                'exists' => Storage::disk('local')->exists($storedFilePath),
            ]);
            // End File upload option setup for import contacts



            if ($storedFilePath === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to store the uploaded file. Please try again.',
                ], 500);
            }
        }

        // ── Atomic template + campaign + import-status creation ───────────────
        $template     = null;
        $campaign     = null;
        $importStatus = null;

        try {
            DB::transaction(function () use (
                $templateData,
                $campaignData,
                $companyId,
                $userId,
                &$template,
                &$campaign,
                &$importStatus
            ) {
                $template = Template::create([
                    'company_id'          => $companyId,
                    'created_by'          => $userId,
                    'name'                => $templateData['name'],
                    'language'            => $templateData['language'] ?? 'en',
                    'category'            => $templateData['category'] ?? null,
                    'media'               => $templateData['media'] ?? null,
                    'media_type'          => $templateData['media_type'] ?? null,
                    'header'              => $templateData['header'] ?? null,
                    'body'                => $templateData['body'] ?? null,
                    'footer'              => $templateData['footer'] ?? null,
                    'button'              => $templateData['button'] ?? null,
                    'button_action_type'  => $templateData['button_action_type'] ?? null,
                    'button_value'        => $templateData['button_value'] ?? null,
                    'button_two'              => $templateData['button_two'] ?? null,
                    'button_two_action_type'  => $templateData['button_two_action_type'] ?? null,
                    'button_two_value'        => $templateData['button_two_value'] ?? null,
                    'status'              => $templateData['status'] ?? 'draft',
                ]);

                $campaign = Campaign::create([
                    'company_id'   => $companyId,
                    'created_by'   => $userId,
                    'template_id'  => $template->id,
                    'name'         => $campaignData['name'],
                    'description'  => $campaignData['description'] ?? null,
                    'status'       => 'draft',
                    'scheduled_at' => $campaignData['scheduled_at'] ?? null,
                ]);

                $importStatus = ContactImportStatus::create([
                    'campaign_id'  => $campaign->id,
                    'status'       => 'pending',
                    'total'        => 0,
                    'inserted'     => 0,
                    'updated'      => 0,
                    'failed_count' => 0,
                    'message'      => 'Contact import is queued and waiting to start.',
                ]);
            });
        } catch (Throwable $e) {
            if ($storedFilePath !== null) {
                Storage::disk('local')->delete($storedFilePath);
            }

            Log::error('CampaignController@setup: transaction failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Campaign creation failed. Please try again.',
            ], 500);
        }

        // ── Dispatch the import job (after commit) ────────────────────────────
        $dispatchSucceeded = false;

        try {
            ImportCampaignContacts::dispatch(
                importStatusId: $importStatus->id,
                campaignId: $campaign->id,
                companyId: $companyId,
                createdBy: $userId,
                type: $inputType,
                filePath: $storedFilePath,
                contactData: $inputType === 'single'
                    ? $request->input('contact')
                    : null,
                phoneNumbers: $inputType === 'phone_numbers'
                    ? $request->input('phone_numbers')
                    : null,
                defaultCountryCode: $request->input('contact.country_code'),
                importBehavior: $importBehavior,
            )->afterCommit();

            $dispatchSucceeded = true;
        } catch (Throwable $e) {
            Log::error('CampaignController@setup: queue dispatch failed.', [
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);

            DB::table('contact_import_statuses')
                ->where('id', $importStatus->id)
                ->update([
                    'status'     => 'failed',
                    'message'    => 'Contact import could not be queued. Please retry.',
                    'updated_at' => now(),
                ]);

            if ($storedFilePath !== null) {
                Storage::disk('local')->delete($storedFilePath);
            }
        }

        $statusUrl = "/api/campaigns/{$campaign->id}/contact-import-status";

        if (! $dispatchSucceeded) {
            return response()->json([
                'success'         => true,
                'message'         => 'Campaign created, but contact import could not be queued.',
                'template_id'     => $template->id,
                'campaign_id'     => $campaign->id,
                'import_method'   => $request->input('import_method', 'auto'),
                'import_behavior' => $importBehavior,
                'contact_import'  => [
                    'status'     => 'failed',
                    'total'      => 0,
                    'inserted'   => 0,
                    'updated'    => 0,
                    'failed'     => 0,
                    'message'    => 'Contact import could not be queued. Please retry.',
                    'status_url' => $statusUrl,
                ],
            ], 201);
        }

        return response()->json([
            'success'         => true,
            'message'         => 'Campaign created successfully. Contact import has started.',
            'template_id'     => $template->id,
            'campaign_id'     => $campaign->id,
            'import_method'   => $request->input('import_method', 'auto'),
            'import_behavior' => $importBehavior,
            'contact_import'  => [
                'status'     => 'pending',
                'total'      => 0,
                'inserted'   => 0,
                'updated'    => 0,
                'failed'     => 0,
                'message'    => 'Contact import is queued and waiting to start.',
                'status_url' => $statusUrl,
            ],
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/campaigns/{campaign_id}/contact-import-status
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return the current contact-import status for a campaign.
     *
     * Authorization: the campaign must belong to the authenticated user's
     * company.  No contact PII is exposed in this response.
     */
    public function contactImportStatus(string|int $campaignId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (empty($user->company_id) || ! is_numeric($campaignId)) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        $campaign = Campaign::where('id', (int) $campaignId)
            ->where('company_id', $user->company_id)
            ->first();

        if ($campaign === null) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        $importStatus = ContactImportStatus::where('campaign_id', $campaignId)->first();

        if ($importStatus === null) {
            return response()->json([
                'success' => false,
                'message' => 'No import status found for this campaign.',
            ], 404);
        }

        return response()->json([
            'success'     => true,
            'campaign_id' => $campaignId,
            'contact_import' => [
                'status'   => $importStatus->status,
                'total'    => $importStatus->total,
                'inserted' => $importStatus->inserted,
                'updated'  => $importStatus->updated,
                'failed'   => $importStatus->failed_count,
                'message'  => $importStatus->message,
            ],
        ]);
    }
}
