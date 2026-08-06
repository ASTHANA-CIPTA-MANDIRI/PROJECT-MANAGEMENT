<?php

namespace App\Filament\Widgets;

use App\Models\TicketActivity;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class LatestActivities extends BaseWidget
{
    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
    ];

    public function mount(): void
    {
        self::$heading = __('Latest tickets activities');
    }

    public static function canView(): bool
    {
        return auth()->user()->can('List tickets');
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getTableQuery(): Builder
    {
        return TicketActivity::query()
            ->with(['oldStatus', 'newStatus', 'user'])
            ->limit(5)
            ->whereHas('ticket', function ($query) {
                return $query->where('owner_id', auth()->user()->id)
                    ->orWhere('responsible_id', auth()->user()->id)
                    ->orWhereHas('project', fn ($query) => $query->accessibleBy(auth()->user()));
            })
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('ticket')
                ->label(__('Ticket'))
                ->formatStateUsing(function ($record, $state) {
                    return new HtmlString('
                    <div class="flex flex-col gap-1">
                        <span class="text-gray-400 font-medium text-xs">
                            '.e($state->project->name).'
                        </span>
                        <span>
                            <a href="'.route('filament.resources.tickets.share', $state->code)
                        .'" target="_blank" class="text-primary-500 text-sm hover:underline">'
                        .e($state->code)
                        .'</a>
                            <span class="text-sm text-gray-400">|</span> '
                        .e($state->name).'
                        </span>
                        <div class="w-full flex items-center gap-2 text-sm">
                            <span style="color: '.e($record->oldStatus->color).'">'
                                .e($record->oldStatus->name)
                            .'</span>
                            <span class="text-gray-500">'.__('To').'</span>
                            <span style="color: '.e($record->newStatus->color).'">
                                '.e($record->newStatus->name).'
                            </span>
                        </div>
                    </div>
                ');
                }),

            Tables\Columns\TextColumn::make('user.name')
                ->label(__('Changed by'))
                ->formatStateUsing(fn ($record) => view('components.user-avatar', ['user' => $record->user])),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Performed at'))
                ->dateTime(),
        ];
    }
}
