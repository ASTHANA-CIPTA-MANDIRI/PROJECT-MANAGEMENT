<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Timesheet;

use Carbon\Carbon;

/**
 * Shared year dropdown for the timesheet report widgets: the current year and
 * the four before it, newest first. Replaces the previously hard-coded
 * 2022/2023 options, which left the widgets empty from 2024 onward.
 */
trait HasYearFilter
{
    /**
     * @return array<int, string>
     */
    protected function yearFilterOptions(): array
    {
        $current = Carbon::now()->year;

        $options = [];
        for ($year = $current; $year >= $current - 4; $year--) {
            $options[$year] = (string) $year;
        }

        return $options;
    }
}
