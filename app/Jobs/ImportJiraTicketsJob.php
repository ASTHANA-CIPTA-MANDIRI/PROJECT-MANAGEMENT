<?php

namespace App\Jobs;

use App\Http\Requests\TicketRequest;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectUser;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class ImportJiraTicketsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $tickets;

    private $user;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tickets, $user)
    {
        $this->tickets = $tickets;
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->tickets && count($this->tickets)) {
            // Atomic batch import: if any ticket/project fails to import, the
            // whole batch rolls back so no partial import is left behind.
            DB::transaction(function () {
                foreach ($this->tickets as $ticket) {
                    $projectDetails = $ticket->fields->project;
                    $ticketData = $ticket->fields;

                    $project = $this->projectFor($projectDetails);

                    Ticket::create($this->validatedTicket($project, $ticketData));
                }
            });

            FilamentNotification::make()
                ->title(__('Jira importation'))
                ->icon('heroicon-o-cloud-download')
                ->body(__('Jira tickets successfully imported'))
                ->sendToDatabase($this->user);
        }
    }

    /**
     * The project the imported tickets go into: an existing project of the
     * importer's, or a new one they own.
     *
     * The lookup is scoped to projects the importer can access. Matching on the
     * name alone across the whole installation meant anyone could name their
     * Jira project after somebody else's and have the import inject tickets —
     * and notifications — into a project they have nothing to do with.
     */
    private function projectFor(object $projectDetails): Project
    {
        $name = Str::substr((string) ($projectDetails->name ?? ''), 0, 255)
            ?: __('Project imported from Jira');

        $project = Project::accessibleBy($this->user)->where('name', $name)->first();

        if ($project) {
            return $project;
        }

        $project = Project::create([
            'name' => $name,
            'description' => __('Project imported from Jira, project key:').($projectDetails->key ?? ''),
            'status_id' => ProjectStatus::where('is_default', true)->firstOrFail()->id,
            'owner_id' => $this->user->id,
            'ticket_prefix' => $this->ticketPrefix($projectDetails->key ?? null, $name),
        ]);

        ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'role' => config('system.projects.affectations.roles.can_manage'),
        ]);

        return $project;
    }

    /**
     * A free ticket prefix derived from the Jira project key. The column is
     * unique across the installation and capped at 3 characters everywhere
     * else in the app, so the key is trimmed to fit and a prefix that is
     * already taken gets a suffix instead of blowing up the whole batch.
     */
    private function ticketPrefix(?string $key, string $projectName): string
    {
        $base = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $key ?: $projectName));
        $base = Str::substr($base, 0, 3) ?: 'JIR';

        if ($this->prefixIsFree($base)) {
            return $base;
        }

        // Keep the first characters recognisable, vary the last one.
        foreach (range(1, 9) as $suffix) {
            $candidate = Str::substr($base, 0, 2).$suffix;
            if ($this->prefixIsFree($candidate)) {
                return $candidate;
            }
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = Str::upper(Str::random(3));
            if ($this->prefixIsFree($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a free ticket prefix for the Jira import.');
    }

    /**
     * Soft-deleted projects keep their row, and the unique index does not care
     * that they are deleted — so they count as taken.
     */
    private function prefixIsFree(string $prefix): bool
    {
        return ! Project::withTrashed()->where('ticket_prefix', $prefix)->exists();
    }

    /**
     * Everything here comes from a remote Jira instance, so it goes through
     * the same rules as a ticket created through the API or the UI instead of
     * straight into Ticket::create().
     *
     * @return array<string, mixed>
     */
    private function validatedTicket(Project $project, object $ticketData): array
    {
        $data = [
            'name' => Str::substr((string) ($ticketData->summary ?? ''), 0, 255)
                ?: __('No name found in jira ticket'),
            'content' => is_string($ticketData->description ?? null) && $ticketData->description !== ''
                ? $ticketData->description
                : __('No content found in jira ticket'),
            'owner_id' => $this->user->id,
            'status_id' => $this->defaultStatusId($project),
            'project_id' => $project->id,
            'type_id' => TicketType::where('is_default', true)->firstOrFail()->id,
            'priority_id' => TicketPriority::where('is_default', true)->firstOrFail()->id,
        ];

        return Validator::make($data, TicketRequest::rulesFor($data))->validate();
    }

    /**
     * The default status of the project being imported into: its own set when
     * it configures custom statuses, the global set otherwise.
     */
    private function defaultStatusId(Project $project): int
    {
        $query = $project->status_type === 'custom'
            ? TicketStatus::where('project_id', $project->id)
            : TicketStatus::whereNull('project_id');

        return $query->orderByDesc('is_default')->orderBy('order')->firstOrFail()->id;
    }
}
