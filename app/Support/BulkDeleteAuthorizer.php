<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Filament gates the per-row delete button with the model's policy, but
 * DeleteBulkAction is only gated by `canDeleteAny()` — a permission-level check
 * that ignores every per-record condition. A user who may delete *some* records
 * could therefore select the whole table and delete records the row button
 * would have refused (e.g. ProjectPolicy::delete also requires ownership or the
 * "can manage" project role).
 *
 * Mirrors Filament\Resources\Resource::can() so bulk deletion allows exactly
 * what the row button allows: a model with no policy — or a policy with no
 * `delete` method — is not policy-protected and stays deletable.
 */
class BulkDeleteAuthorizer
{
    public static function allows(Model $record): bool
    {
        $policy = Gate::getPolicyFor($record);

        if ($policy === null || ! method_exists($policy, 'delete')) {
            return true;
        }

        return Gate::allows('delete', $record);
    }
}
