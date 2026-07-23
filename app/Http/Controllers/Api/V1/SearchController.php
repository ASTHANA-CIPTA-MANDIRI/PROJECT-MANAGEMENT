<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\TicketCommentResource;
use App\Http\Resources\TicketResource;
use App\Services\Search\SearchService;
use Illuminate\Http\Request;

class SearchController extends ApiController
{
    /**
     * GET /api/v1/search?q=...&limit=...
     *
     * Full-text search across the projects, tickets and comments the
     * authenticated user can access.
     */
    public function __invoke(Request $request, SearchService $search)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $results = $search->search(
            $request->user(),
            $validated['q'],
            $validated['limit'] ?? 10
        );

        return response()->json([
            'query' => $validated['q'],
            'data' => [
                'projects' => ProjectResource::collection($results['projects']),
                'tickets' => TicketResource::collection($results['tickets']),
                'comments' => TicketCommentResource::collection($results['comments']),
            ],
            'meta' => [
                'projects_count' => $results['projects']->count(),
                'tickets_count' => $results['tickets']->count(),
                'comments_count' => $results['comments']->count(),
            ],
        ]);
    }
}
