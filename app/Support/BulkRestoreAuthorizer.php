<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Filament gates the per-row restore button with the model's policy, but
 * RestoreBulkAction is only gated by `canRestoreAny()` — a permission-level check
 * that ignores every per-record condition. A user who may restore *some* records
 * could therefore select the whole table and restore records the row button
 * would have refused (e.g. UserPolicy::restore also refuses restoring a
 * Super Admin account unless the acting user is a Super Admin too).
 *
 * Mirrors BulkDeleteAuthorizer so bulk restoration allows exactly what the row
 * button allows: a model with no policy — or a policy with no `restore` method
 * — is not policy-protected and stays restorable.
 */
class BulkRestoreAuthorizer
{
    public static function allows(Model $record): bool
    {
        $policy = Gate::getPolicyFor($record);

        if ($policy === null || ! method_exists($policy, 'restore')) {
            return true;
        }

        return Gate::allows('restore', $record);
    }
}
