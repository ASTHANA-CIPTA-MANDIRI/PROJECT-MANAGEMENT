<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\OrganizationContext;
use App\Support\TrialGate;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TimesheetExport extends AuthorizedPage implements HasForms
{
    use InteractsWithForms;

    /** Same permission as the timesheet list this page dumps to CSV. */
    protected static ?string $permission = 'List timesheet data';

    protected static ?string $slug = 'timesheet-export';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.timesheet-export';

    protected static function getNavigationGroup(): ?string
    {
        return __('Timesheet');
    }

    protected function getTitle(): string
    {
        return __('Timesheet export');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getFormSchema(): array
    {
        return [
            Card::make()->schema([
                Grid::make()
                    ->columns(2)
                    ->schema([
                        DatePicker::make('start_date')
                            ->displayFormat('d M Y')
                            ->required()
                            ->reactive()
                            ->label(__('Start date')),
                        DatePicker::make('end_date')
                            ->displayFormat('d M Y')
                            ->required()
                            ->reactive()
                            ->afterOrEqual(fn (Closure $get) => $get('start_date'))
                            ->label(__('End date')),
                    ]),
            ]),
        ];
    }

    /**
     * Quick presets for the two date fields. They only fill the form; the
     * same validation (required, end >= start) and the same export run after.
     */
    public function setRange(string $range): void
    {
        $now = Carbon::now();

        $dates = match ($range) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => null,
        };

        if ($dates === null) {
            return;
        }

        // The form has no state path: its fields are plain component properties.
        $this->fill([
            'start_date' => $dates[0]->toDateString(),
            'end_date' => $dates[1]->toDateString(),
        ]);
    }

    /**
     * Column headings of the CSV, for the "what is inside" panel. Kept next to
     * the real export so the two cannot drift silently.
     *
     * @return array<int, string>
     */
    public function exportColumns(): array
    {
        return (new \App\Exports\TimesheetExport([]))->headings();
    }

    public function create(): ?BinaryFileResponse
    {
        // Audit finding (pre-Fase 7): this page is only gated by the flat
        // 'List timesheet data' permission (AuthorizesPageAccess::boot()
        // calls auth()->user()?->can($permission) with no model argument),
        // which Gate::before()'s TRIAL_GATED_MODELS matching never sees -
        // a locked-out Organization could still export their timesheet CSV
        // indefinitely. Mirrors JiraImport::import()'s identical fix.
        $organization = OrganizationContext::current(auth()->user());

        if ($organization !== null && ! TrialGate::active($organization)) {
            $this->notify('danger', __('Your organization\'s trial has ended. Contact the organization Owner to subscribe.'));

            return null;
        }

        $data = $this->form->getState();

        return Excel::download(
            new \App\Exports\TimesheetExport($data),
            'time_'.time().'.csv',
            \Maatwebsite\Excel\Excel::CSV,
            ['Content-Type' => 'text/csv']
        );
    }
}
