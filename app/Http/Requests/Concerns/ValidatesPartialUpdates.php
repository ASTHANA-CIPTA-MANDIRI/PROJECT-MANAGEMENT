<?php

namespace App\Http\Requests\Concerns;

/**
 * PUT and PATCH ask for different amounts of the resource: PUT replaces it, so
 * every rule stands as written, while PATCH only carries the fields that
 * change. Marking the rules "sometimes" on PATCH lets a client rename a
 * project without resending — and risking clobbering — everything else.
 */
trait ValidatesPartialUpdates
{
    /**
     * Make every rule conditional when the request is a PATCH.
     *
     * Fields the request stamps itself (owner, parent ids) are always present
     * in the payload, so "sometimes" never weakens the rules guarding them.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function whenPartial(array $rules): array
    {
        if (! $this->isMethod('PATCH')) {
            return $rules;
        }

        return array_map(
            fn (array $rule) => array_merge(['sometimes'], $rule),
            $rules
        );
    }
}
