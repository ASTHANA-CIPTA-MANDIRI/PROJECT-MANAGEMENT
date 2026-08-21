<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPartialUpdates;
use App\Models\Sprint;
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
            return $user->can('Create sprint');
        }

        // An update is judged against the sprint itself, here rather than in
        // the controller alone: authorization runs before validation, so a
        // caller who does not manage the project is turned away first.
        return ($sprint = $this->routeSprint())
            ? $user->can('update', $sprint)
            : $user->can('Update sprint');
    }

    /**
     * On update, fill in what the sprint itself already answers.
     *
     * The project is not one of them: a sprint belongs to the project it was
     * planned in — its epic and its tickets live there — so an update never
     * moves it elsewhere. The dates are checked against each other, so when
     * only one side is sent the other has to come from the stored sprint
     * rather than go missing and compare against nothing.
     */
    protected function prepareForValidation(): void
    {
        if (! $sprint = $this->routeSprint()) {
            return;
        }

        $this->merge([
            'project_id' => $sprint->project_id,
            'starts_at' => $this->input('starts_at', optional($sprint->starts_at)->toDateString()),
            'ends_at' => $this->input('ends_at', optional($sprint->ends_at)->toDateString()),
        ]);
    }

    /**
     * The sprint being updated, when the request went through a route that
     * binds one. Null on create and outside the HTTP lifecycle.
     */
    protected function routeSprint(): ?Sprint
    {
        $sprint = $this->route('sprint');

        return $sprint instanceof Sprint ? $sprint : null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->whenPartial([
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'before_or_equal:ends_at'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'description' => ['nullable', 'string'],
            'project_id' => ['required', Rule::exists('projects', 'id')->whereNull('deleted_at')],
        ]);
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
