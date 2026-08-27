<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A unique-value validation rule that is aware of SoftDeletes. A plain
 * `unique` rule either checks every row including trashed ones (locking the
 * value forever, even for a record the user could restore instead) or is
 * scoped to `deleted_at IS NULL` (letting a value already held by a
 * soft-deleted row pass validation, only to hit the database's own unique
 * index - which knows nothing about `deleted_at` - and throw a raw
 * QueryException on save).
 *
 * This rule instead always finds the conflict, but tells trashed and active
 * conflicts apart: an active duplicate fails the normal way, while a
 * duplicate held only by a trashed record fails with a message that says so
 * and points at restoring it - the fix an admin actually needs. See
 * docs/soft-deletes.md.
 */
class UniqueAmongTrashedRule
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function make(
        string $modelClass,
        string $column,
        int|string|null $ignoreId,
        string $takenMessage,
        string $trashedMessage,
    ): Closure {
        return function (string $attribute, mixed $value, Closure $fail) use ($modelClass, $column, $ignoreId, $takenMessage, $trashedMessage): void {
            // withoutGlobalScope(SoftDeletingScope), not withTrashed(): the
            // latter only exists via the SoftDeletes trait, which PHPStan
            // cannot verify for an arbitrary class-string<Model>.
            /** @var Builder<Model> $query */
            $query = $modelClass::query()->withoutGlobalScope(SoftDeletingScope::class);

            $matching = $query
                ->where($column, $value)
                ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
                ->first();

            if ($matching === null) {
                return;
            }

            // ->trashed() only exists via the SoftDeletes trait, which
            // PHPStan cannot verify for an arbitrary class-string<Model>;
            // reading the column directly is equivalent and always typed.
            $fail($matching->getAttribute('deleted_at') !== null ? $trashedMessage : $takenMessage);
        };
    }
}
