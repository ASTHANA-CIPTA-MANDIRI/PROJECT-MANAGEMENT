<?php

namespace App\Services\Search;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Full-text search across projects, tickets and comments via Laravel Scout,
 * scoped to the projects the user can access.
 *
 * Access scoping is pushed down to the search engine with whereIn() filters
 * instead of loading every hit and filtering in PHP, so it scales the same way
 * on the collection driver (default) and Meilisearch/Elasticsearch. The filter
 * keys (id / project_id / ticket_id) are real columns and indexed attributes,
 * so they resolve on both. For Meilisearch they must also be declared
 * filterable — see config/scout.php.
 */
class SearchService
{
    /**
     * @return array{projects: Collection, tickets: Collection, comments: Collection}
     */
    public function search(User $user, string $query, int $limit = 10): array
    {
        $query = trim($query);

        $empty = ['projects' => collect(), 'tickets' => collect(), 'comments' => collect()];

        if ($query === '') {
            return $empty;
        }

        $projectIds = $this->accessibleProjectIds($user)->all();

        if ($projectIds === []) {
            return $empty;
        }

        $projects = Project::search($query)
            ->whereIn('id', $projectIds)
            ->take($limit)
            ->get();

        $tickets = Ticket::search($query)
            ->whereIn('project_id', $projectIds)
            ->take($limit)
            ->get();

        // Filtered by project_id directly (denormalized onto the comment's
        // search index, see TicketComment::toSearchableArray) instead of
        // first pulling every accessible project's ticket ids into memory.
        $comments = TicketComment::search($query)
            ->whereIn('project_id', $projectIds)
            ->take($limit)
            ->get()
            ->load('ticket:id,project_id');

        return compact('projects', 'tickets', 'comments');
    }

    /**
     * Ids of projects the user owns or belongs to.
     */
    private function accessibleProjectIds(User $user): Collection
    {
        return Project::query()
            ->accessibleBy($user)
            ->pluck('id');
    }
}
