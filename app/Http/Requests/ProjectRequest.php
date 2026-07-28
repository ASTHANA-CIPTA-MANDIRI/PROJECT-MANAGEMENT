<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for creating and updating a Project.
 *
 * Mirrors the Filament ProjectResource form and the `projects` table schema,
 * so the same rules can be reused by API endpoints, CLI commands and tests.
 */
class ProjectRequest extends FormRequest
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
            ? $user->can('Create project')
            : $user->can('Update project');
    }

    /**
     * On create the owner is always the authenticated user: force owner_id and
     * ignore any value from the body, so a project cannot be attributed to
     * someone else. (Updates keep whatever owner_id is provided.)
     */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && $this->user()) {
            $this->merge(['owner_id' => $this->user()->getKey()]);
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'status_id' => ['required', Rule::exists('project_statuses', 'id')],
            'ticket_prefix' => [
                'required',
                'string',
                'max:3',
                Rule::unique('projects', 'ticket_prefix')
                    ->ignore($projectId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::in(['kanban', 'scrum'])],
            'status_type' => ['required', Rule::in(['default', 'custom'])],
        ];
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
            'ticket_prefix.unique' => __('This ticket prefix is already used by another project.'),
            'type.in' => __('The project type must be either Kanban or Scrum.'),
        ];
    }
}
