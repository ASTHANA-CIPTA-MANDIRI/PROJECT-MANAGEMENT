<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules for creating and updating a Ticket.
 *
 * Mirrors the Filament TicketResource form and the `tickets` table schema.
 * Note: `code` and `order` are generated automatically in the Ticket model's
 * boot lifecycle, so they are intentionally not validated as user input.
 *
 * Every relation a ticket points at has to belong to the same project as the
 * ticket itself: "the id exists somewhere in the table" would let a caller
 * hang their ticket off another project's sprint, epic or status. The rules
 * therefore read project_id from the payload — callers outside the HTTP
 * lifecycle must build them through rulesFor().
 */
class TicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $this->isMethod('POST')
            ? $user->can('Create ticket')
            : $user->can('Update ticket');
    }

    /**
     * Fill in values the API derives from the route/session before validating:
     * the parent project (nested route) and a default owner (current user).
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        if ($project = $this->route('project')) {
            $data['project_id'] = is_object($project) ? $project->getKey() : $project;
        }

        if (! $this->filled('owner_id') && $this->user()) {
            $data['owner_id'] = $this->user()->getKey();
        }

        if ($data) {
            $this->merge($data);
        }
    }

    /**
     * Rules for a payload validated outside the HTTP request lifecycle (the
     * Livewire forms). The project-scoped rules read project_id from the
     * request, so the data has to be handed over rather than passed to the
     * validator alone.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function rulesFor(array $data): array
    {
        return (new self)->merge($data)->rules();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->project();

        return [
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'project_id' => ['required', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'owner_id' => ['required', $this->contributorRule($project)],
            'responsible_id' => ['nullable', $this->contributorRule($project)],
            'status_id' => ['required', $this->statusRule($project)],
            'type_id' => ['required', Rule::exists('ticket_types', 'id')],
            'priority_id' => ['required', Rule::exists('ticket_priorities', 'id')],
            'estimation' => ['nullable', 'numeric', 'min:0'],
            'epic_id' => ['nullable', $this->sameProjectRule('epics', $project)],
            'sprint_id' => ['nullable', $this->sameProjectRule('sprints', $project)],
        ];
    }

    /**
     * The project the ticket belongs to, as far as the payload lets us tell.
     * A null project only means "cannot be scoped": either project_id is
     * missing, and its own rule fails the request, or the rules were built
     * without the payload at hand.
     */
    protected function project(): ?Project
    {
        $projectId = $this->input('project_id');

        // A non-scalar id is nonsense the project_id rule rejects on its own;
        // it must not reach find(), which would return a collection.
        return is_scalar($projectId) && $projectId ? Project::find($projectId) : null;
    }

    /**
     * An epic or sprint may only be attached to a ticket of its own project.
     * Otherwise the ticket shows up in another project's burndown and velocity,
     * and TicketObserver copies that project's epic onto it.
     */
    private function sameProjectRule(string $table, ?Project $project): Exists
    {
        $rule = Rule::exists($table, 'id')->whereNull('deleted_at');

        return $project ? $rule->where('project_id', $project->getKey()) : $rule;
    }

    /**
     * Ticket statuses are either global (shared by every "default" project) or
     * scoped to one "custom" project, never both — the same split the boards
     * and the forms apply when they list the available statuses.
     */
    private function statusRule(?Project $project): Exists
    {
        $rule = Rule::exists('ticket_statuses', 'id');

        if (! $project) {
            return $rule;
        }

        return $project->status_type === 'custom'
            ? $rule->where('project_id', $project->getKey())
            : $rule->whereNull('project_id');
    }

    /**
     * Owner and responsible must be contributors of the project (its owner or
     * one of its members): anyone else would start receiving notifications
     * about a project they cannot even open.
     */
    private function contributorRule(?Project $project): Exists
    {
        $rule = Rule::exists('users', 'id')->whereNull('deleted_at');

        if (! $project) {
            return $rule;
        }

        return $rule->whereIn('id', $project->users()
            ->pluck('users.id')
            ->push($project->owner_id)
            ->unique()
            ->all());
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estimation.numeric' => __('The estimation must be a number of hours.'),
            'estimation.min' => __('The estimation cannot be negative.'),
        ];
    }
}
