<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class LatestTickets extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
    ];

    public function mount(): void
    {
        self::$heading = __('Latest tickets');
    }

    public static function canView(): bool
    {
        return auth()->user()->can('List tickets');
    }

    protected function getTableQuery(): Builder
    {
        return Ticket::query()
            ->with(['project', 'responsible', 'status', 'type'])
            ->limit(5)
            ->where(function ($query) {
                return $query->where('owner_id', auth()->user()->id)
                    ->orWhere('responsible_id', auth()->user()->id)
                    ->orWhereHas('project', fn ($query) => $query->accessibleBy(auth()->user()));
            })
            ->latest();
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('Ticket'))
                ->formatStateUsing(fn ($record) => new HtmlString('
                    <div class="flex flex-col gap-1">
                        <span class="text-gray-400 font-medium text-xs">
                            '.e($record->project->name).'
                        </span>
                        <span>
                            <a href="'.route('filament.resources.tickets.share', $record->code)
                    .'" target="_blank" class="text-primary-500 text-sm hover:underline">'
                    .e($record->code)
                    .'</a>
                            <span class="text-sm text-gray-400">|</span> '
                    .e($record->name).'
                        </span>
                        '.($record->responsible ? '
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-1 text-xs text-gray-400">'
                        .view('components.user-avatar', ['user' => $record->responsible])->render()
                        .'<span>'.e($record->responsible?->name).'</span>'
                        .'</div>
                        </div>' : '').'
                    </div>
                ')),

            Tables\Columns\TextColumn::make('status.name')
                ->label(__('Status'))
                ->formatStateUsing(fn ($record) => view('components.color-badge', [
                    'color' => $record->status->color,
                    'title' => $record->status->name,
                    'class' => 'mt-1',
                ])),

            Tables\Columns\TextColumn::make('type.name')
                ->label(__('Type'))
                ->formatStateUsing(fn ($record) => view('components.ticket-type', ['type' => $record->type])),

            Tables\Columns\TextColumn::make('priority.name')
                ->label(__('Priority'))
                ->formatStateUsing(fn ($record) => view('components.color-badge', [
                    'color' => $record->priority->color,
                    'title' => $record->priority->name,
                    'class' => 'mt-1',
                ])),
        ];
    }
}
