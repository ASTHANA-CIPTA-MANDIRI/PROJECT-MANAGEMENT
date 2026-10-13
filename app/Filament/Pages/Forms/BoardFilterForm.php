<?php

namespace App\Filament\Pages\Forms;

use App\Models\Label;
use App\Models\Project;
use App\Models\TicketPriority;
use App\Models\TicketType;
use App\Support\UserOptions;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\HtmlString;

/**
 * Filter bar for the Kanban/Scrum boards (owners, types, priorities and the
 * unaffected-only toggle), extracted from KanbanScrumHelper for readability.
 * The Filter / Reset buttons drive the board's wire:click handlers.
 */
class BoardFilterForm
{
    /**
     * @param  Project|null  $project  The board's project, when the board shows
     *                                 one; scopes the people filter to it.
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function schema(?Project $project = null): array
    {
        return [
            Grid::make([
                'default' => 1,
                'md' => 4,
            ])
                ->schema([
                    Select::make('users')
                        ->label(__('Owners / Responsibles'))
                        ->multiple()
                        ->options(fn () => UserOptions::forProject($project)),

                    Select::make('types')
                        ->label(__('Ticket types'))
                        ->multiple()
                        ->options(TicketType::query()->visibleTo(auth()->user())->pluck('name', 'id')),

                    Select::make('priorities')
                        ->label(__('Ticket priorities'))
                        ->multiple()
                        ->options(TicketPriority::query()->visibleTo(auth()->user())->pluck('name', 'id')),

                    Select::make('labels')
                        ->label(__('Labels'))
                        ->multiple()
                        ->options(Label::query()->visibleTo(auth()->user())->pluck('name', 'id')),

                    Toggle::make('includeNotAffectedTickets')
                        ->label(__('Show only not affected tickets'))
                        ->columnSpan(['default' => 1, 'md' => 2]),

                    Placeholder::make('search')
                        ->disableLabel()
                        ->columnSpan(['default' => 1, 'md' => 2])
                        ->content(new HtmlString('
                            <div class="rk-filter-actions">
                            <button type="button"
                                    wire:click="filter" wire:loading.attr="disabled"
                                    class="rk-filter-btn">
                                '.__('Filter').'
                            </button>
                            <button type="button"
                                    wire:click="resetFilters" wire:loading.attr="disabled"
                                    class="rk-filter-btn rk-filter-btn--ghost">
                                '.__('Reset filters').'
                            </button>
                            </div>
                        ')),
                ]),
        ];
    }
}
