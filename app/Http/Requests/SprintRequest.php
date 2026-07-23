<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for creating and updating a Sprint.
 *
 * Mirrors the Filament SprintsRelationManager form and the `sprints` table
 * schema. The start date must be on or before the end date, matching the
 * beforeOrEqual / afterOrEqual constraints in the Filament form.
 */
class SprintRequest extends FormRequest
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
            ? $user->can('Create sprint')
            : $user->can('Update sprint');
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
            'starts_at' => ['required', 'date', 'before_or_equal:ends_at'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'description' => ['nullable', 'string'],
            'project_id' => ['required', Rule::exists('projects', 'id')->whereNull('deleted_at')],
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
            'starts_at.before_or_equal' => __('The sprint start date must be on or before the end date.'),
            'ends_at.after_or_equal' => __('The sprint end date must be on or after the start date.'),
        ];
    }
}
