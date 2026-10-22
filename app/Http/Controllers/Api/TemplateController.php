<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListTemplatesRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use Illuminate\Http\JsonResponse;

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
}
