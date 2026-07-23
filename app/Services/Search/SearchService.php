<?php

namespace App\Services\Search;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Full-text search across projects, tickets and comments via Laravel Scout,
 * scoped to the projects the user can access. Driver-agnostic: works with the
 * collection driver (default) and Meilisearch/Elasticsearch in production.
 */
class SearchService
{
    /**
     * @return array{projects: Collection, tickets: Collection, comments: Collection}
     */
    public function search(User $user, string $query, int $limit = 10): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['projects' => collect(), 'tickets' => collect(), 'comments' => collect()];
        }

        $projectIds = $this->accessibleProjectIds($user);

        $projects = Project::search($query)->get()
            ->filter(fn (Project $p) => $projectIds->contains($p->id))
            ->take($limit)
            ->values();

        $tickets = Ticket::search($query)->get()
            ->filter(fn (Ticket $t) => $projectIds->contains($t->project_id))
            ->take($limit)
            ->values();

        $comments = TicketComment::search($query)->get()
            ->load('ticket:id,project_id')
            ->filter(fn (TicketComment $c) => $c->ticket && $projectIds->contains($c->ticket->project_id))
            ->take($limit)
            ->values();

        return compact('projects', 'tickets', 'comments');
    }

    /**
     * Ids of projects the user owns or belongs to.
     */
    private function accessibleProjectIds(User $user): Collection
    {
        return Project::query()
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
            })
            ->pluck('id');
    }
}
