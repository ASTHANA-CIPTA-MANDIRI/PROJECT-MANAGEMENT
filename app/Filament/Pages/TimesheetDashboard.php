<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Timesheet\ActivitiesReport;
use App\Filament\Widgets\Timesheet\MonthlyReport;
use App\Filament\Widgets\Timesheet\WeeklyReport;
use App\Models\TicketHour;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;

class TimesheetDashboard extends AuthorizedPage
{
    protected static ?string $permission = 'View timesheet dashboard';

    protected static ?string $slug = 'timesheet-dashboard';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament::pages.dashboard';

    protected function getColumns(): int|array
    {
        return 6;
    }

    protected static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Timesheet');
    }

    protected function getWidgets(): array
    {
        return [
            MonthlyReport::class,
            ActivitiesReport::class,
            WeeklyReport::class,
        ];
    }

    protected function getTitle(): string
    {
        return __('Timesheet dashboard');
    }

    protected function getHeader(): ?View
    {
        return view('filament.pages.timesheet-summary', [
            'summary' => $this->summary(),
            'heading' => $this->getTitle(),
        ]);
    }

    /**
     * Read-only headline numbers for the logged-in user, the same scope the
     * three report widgets use (their own ticket_hours, by created_at).
     *
     * @return array{week: float, month: float, year: float, topActivity: ?string, topActivityHours: float}
     */
    public function summary(): array
    {
        $userId = auth()->id();
        $now = Carbon::now();

        $user = auth()->user();

        $sum = fn (Carbon $from, Carbon $to): float => (float) TicketHour::query()
            ->unlessOrganizationLocked($user)
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('value');

        $top = TicketHour::query()
            ->unlessOrganizationLocked($user)
            ->with('activity')
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$now->copy()->startOfYear(), $now->copy()->endOfYear()])
            ->get()
            ->groupBy('activity_id')
            ->map(fn ($group) => ['name' => $group->first()->activity?->name, 'hours' => (float) $group->sum('value')])
            ->sortByDesc('hours')
            ->first();

        return [
            'week' => $sum($now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
            'month' => $sum($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'year' => $sum($now->copy()->startOfYear(), $now->copy()->endOfYear()),
            'topActivity' => $top ? ($top['name'] ?? __('No activity')) : null,
            'topActivityHours' => $top['hours'] ?? 0.0,
        ];
    }
}
