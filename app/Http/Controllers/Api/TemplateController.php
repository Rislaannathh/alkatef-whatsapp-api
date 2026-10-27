<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListTemplatesRequest;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/templates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a paginated list of templates belonging to the authenticated
     * user's company.
     *
     * Supports optional filtering by name (search), status, and category.
     * Company-level isolation is enforced here — never via client input.
     */
    public function index(ListTemplatesRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $perPage = (int) ($request->input('per_page', 15));

        if (empty($user->company_id)) {
            return response()->json([
                'success'    => true,
                'message'    => 'Templates retrieved successfully.',
                'data'       => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $perPage,
                    'total'        => 0,
                    'last_page'    => 1,
                ],
            ]);
        }

        $templates = Template::query()
            ->where('company_id', $user->company_id)
            ->with('templateCategory')
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->where('category', $request->input('category'));
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success'    => true,
            'message'    => 'Templates retrieved successfully.',
            'data'       => TemplateResource::collection($templates),
            'pagination' => [
                'current_page' => $templates->currentPage(),
                'per_page'     => $templates->perPage(),
                'total'        => $templates->total(),
                'last_page'    => $templates->lastPage(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/templates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store a newly created template in storage.
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (empty($user->company_id)) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to a company.',
            ], 422);
        }

        $validated = $request->validated();
        $validated['company_id'] = $user->company_id;
        $validated['created_by'] = $user->id;

        $template = Template::create($validated);
        $template->load('templateCategory');

        return response()->json([
            'success' => true,
            'message' => 'Template created successfully.',
            'data'    => new TemplateResource($template),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/templates/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Display the specified template.
     */
    public function show(Request $request, string|int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (empty($user->company_id) || ! is_numeric($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $template = Template::where('id', (int) $id)
            ->where('company_id', $user->company_id)
            ->with('templateCategory')
            ->first();

        if (! $template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template retrieved successfully.',
            'data'    => new TemplateResource($template),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT/PATCH /api/templates/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update the specified template in storage.
     */
    public function update(UpdateTemplateRequest $request, string|int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (empty($user->company_id) || ! is_numeric($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $template = Template::where('id', (int) $id)
            ->where('company_id', $user->company_id)
            ->first();

        if (! $template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $template->update($request->validated());
        $template->load('templateCategory');

        return response()->json([
            'success' => true,
            'message' => 'Template updated successfully.',
            'data'    => new TemplateResource($template),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/templates/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Remove the specified template from storage.
     */
    public function destroy(Request $request, string|int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (empty($user->company_id) || ! is_numeric($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $template = Template::where('id', (int) $id)
            ->where('company_id', $user->company_id)
            ->first();

        if (! $template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully.',
        ]);
    }
}
