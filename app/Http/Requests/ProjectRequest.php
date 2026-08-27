<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPartialUpdates;
use App\Models\Project;
use App\Support\UniqueAmongTrashedRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules for creating and updating a Project.
 *
 * Mirrors the Filament ProjectResource form and the `projects` table schema,
 * so the same rules can be reused by API endpoints, CLI commands and tests.
 */
class ProjectRequest extends FormRequest
{
    use ValidatesPartialUpdates;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($this->isMethod('POST')) {
            return $user->can('Create project');
        }

        // An update is judged against the project itself, here rather than in
        // the controller alone: authorization runs before validation, so an
        // outsider is turned away before the rules answer questions about the
        // project's members and ticket prefix.
        return ($project = $this->routeProject())
            ? $user->can('update', $project)
            : $user->can('Update project');
    }

    /**
     * On create the owner is always the authenticated user: force owner_id and
     * ignore any value from the body, so a project cannot be attributed to
     * someone else.
     *
     * On update the owner may be handed over (see ownerRule below), but an
     * omitted owner_id keeps the project where it is instead of failing the
     * "required" rule on a PUT that only means to rename it.
     */
    protected function prepareForValidation(): void
    {
        if (! $user = $this->user()) {
            return;
        }

        if ($this->isMethod('POST')) {
            $this->merge(['owner_id' => $user->getKey()]);

            return;
        }

        if (! $this->filled('owner_id') && $project = $this->routeProject()) {
            $this->merge(['owner_id' => $project->owner_id]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // On update the project is available as a route parameter; used to
        // ignore the current record when checking the unique ticket prefix.
        $projectId = $this->route('project')?->id ?? $this->route('project');

        return $this->whenPartial([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['required', $this->ownerRule()],
            'status_id' => ['required', Rule::exists('project_statuses', 'id')],
            'ticket_prefix' => [
                'required',
                'string',
                'max:3',
                // ->whereNull('deleted_at') ignored trashed projects, so a
                // trashed project's prefix passed this rule only to hit the
                // database's own unique index (no deleted_at awareness) and
                // throw a raw QueryException on save. UniqueAmongTrashedRule
                // matches what ProjectForm (the Filament panel) already does -
                // see M-3 in docs/soft-deletes.md.
                UniqueAmongTrashedRule::make(
                    Project::class,
                    'ticket_prefix',
                    $projectId,
                    __('This ticket prefix is already used by another project.'),
                    __('This ticket prefix belongs to a deleted project. Restore that project to reuse it, or choose a different prefix.'),
                ),
            ],
            'type' => ['required', Rule::in(['kanban', 'scrum'])],
            'status_type' => ['required', Rule::in(['default', 'custom'])],
        ]);
    }

    /**
     * The project being updated, when the request went through a route that
     * binds one. Null on create, and on the rule sets built outside the HTTP
     * lifecycle (the Livewire forms and the tests).
     */
    protected function routeProject(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }

    /**
     * An existing project may only be handed to someone already on it — its
     * current owner or one of its members. Any other user would suddenly own a
     * project, and everything filed under it, that they were never given
     * access to. On create the owner is the caller, so the plain rule is enough.
     */
    private function ownerRule(): Exists
    {
        $rule = Rule::exists('users', 'id')->whereNull('deleted_at');

        if (! $project = $this->routeProject()) {
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
            'ticket_prefix.max' => __('The ticket prefix may not be greater than 3 characters.'),
            // No 'ticket_prefix.unique' entry: the closure rule
            // (UniqueAmongTrashedRule) fails via $fail() with its own message
            // directly, so it never falls back to a rule-name-keyed message.
            'type.in' => __('The project type must be either Kanban or Scrum.'),
        ];
    }
}
