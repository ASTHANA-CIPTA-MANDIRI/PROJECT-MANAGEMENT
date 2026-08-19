<?php

use App\Support\Colors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Filament 2's ColorPicker never validated its value server-side before this,
 * so any `color` column could hold a free-text string instead of a 6-digit
 * hex code - and every one of them is interpolated straight into a
 * `style="background-color: ..."` attribute. The form field is now validated
 * (see the ColorPicker::regex() calls added alongside this migration) and
 * every render site filters through Colors::safe() as a second guard, but
 * rows written before either existed can still hold something else.
 *
 * Matching is done in PHP against the same Colors::HEX_PATTERN the app uses,
 * rather than a SQL regex, so there is exactly one definition of "valid" and
 * it works the same on the MySQL used in production and the SQLite used in
 * tests.
 */
return new class extends Migration
{
    /** @var array<string, string> table => the color this table's rows default to */
    private const TABLES = [
        'project_statuses' => '#cecece',
        'ticket_statuses' => '#cecece',
        'ticket_types' => '#cecece',
        'ticket_priorities' => '#cecece',
        'labels' => '#3b82f6',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $default) {
            $invalidIds = DB::table($table)
                ->whereNotNull('color')
                ->pluck('color', 'id')
                ->reject(fn (string $color) => preg_match(Colors::HEX_PATTERN, $color) === 1)
                ->keys();

            if ($invalidIds->isNotEmpty()) {
                DB::table($table)->whereIn('id', $invalidIds)->update(['color' => $default]);
            }
        }
    }

    /**
     * Not reversible: the original invalid values are not recorded anywhere,
     * so there is nothing meaningful to restore them to.
     */
    public function down(): void
    {
        //
    }
};
