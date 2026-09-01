<?php

namespace App\Rules;

use App\Support\JiraHost;
use Illuminate\Contracts\Validation\InvokableRule;
use InvalidArgumentException;

/**
 * Form-level counterpart of App\Support\JiraHost, so the import wizard reports
 * a refused host as a field error instead of failing at connect time.
 */
class SafeJiraHost implements InvokableRule
{
    public function __invoke($attribute, $value, $fail)
    {
        try {
            JiraHost::sanitize((string) $value);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }
}
