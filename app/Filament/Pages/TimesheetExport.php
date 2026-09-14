<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\OrganizationContext;
use App\Support\TrialGate;
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
                            ->required()
                            ->reactive()
                            ->label('Star date'),
                        DatePicker::make('end_date')
                            ->required()
                            ->reactive()
                            ->afterOrEqual(fn (Closure $get) => $get('start_date'))
                            ->label('End date'),
                    ]),
            ]),
        ];
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
