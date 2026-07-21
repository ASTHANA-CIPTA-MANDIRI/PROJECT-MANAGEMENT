<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for creating and updating a Ticket.
 *
 * Mirrors the Filament TicketResource form and the `tickets` table schema.
 * Note: `code` and `order` are generated automatically in the Ticket model's
 * boot lifecycle, so they are intentionally not validated as user input.
 */
class TicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'project_id' => ['required', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'owner_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'responsible_id' => ['nullable', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'status_id' => ['required', Rule::exists('ticket_statuses', 'id')],
            'type_id' => ['required', Rule::exists('ticket_types', 'id')],
            'priority_id' => ['required', Rule::exists('ticket_priorities', 'id')],
            'estimation' => ['nullable', 'numeric', 'min:0'],
            'epic_id' => ['nullable', Rule::exists('epics', 'id')->whereNull('deleted_at')],
            'sprint_id' => ['nullable', Rule::exists('sprints', 'id')->whereNull('deleted_at')],
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
            'estimation.numeric' => __('The estimation must be a number of hours.'),
            'estimation.min' => __('The estimation cannot be negative.'),
        ];
    }
}
