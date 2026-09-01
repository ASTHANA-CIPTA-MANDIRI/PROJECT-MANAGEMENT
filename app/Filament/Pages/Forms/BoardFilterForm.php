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
                'default' => 2,
                'md' => 6,
            ])
                ->schema([
                    Select::make('users')
                        ->label(__('Owners / Responsibles'))
                        ->multiple()
                        ->options(fn () => UserOptions::forProject($project)),

                    Select::make('types')
                        ->label(__('Ticket types'))
                        ->multiple()
                        ->options(TicketType::query()->pluck('name', 'id')),

                    Select::make('priorities')
                        ->label(__('Ticket priorities'))
                        ->multiple()
                        ->options(TicketPriority::query()->pluck('name', 'id')),

                    Select::make('labels')
                        ->label(__('Labels'))
                        ->multiple()
                        ->options(Label::query()->pluck('name', 'id')),

                    Toggle::make('includeNotAffectedTickets')
                        ->label(__('Show only not affected tickets'))
                        ->columnSpan(2),

                    Placeholder::make('search')
                        ->label(new HtmlString('&nbsp;'))
                        ->content(new HtmlString('
                            <button type="button"
                                    wire:click="filter" wire:loading.attr="disabled"
                                    class="bg-primary-500 px-3 py-2 text-white rounded hover:bg-primary-600
                                    disabled:bg-primary-300">
                                '.__('Filter').'
                            </button>
                            <button type="button"
                                    wire:click="resetFilters" wire:loading.attr="disabled"
                                    class="ml-2 bg-gray-800 px-3 py-2 text-white rounded hover:bg-gray-900
                                    disabled:bg-gray-300">
                                '.__('Reset filters').'
                            </button>
                        ')),
                ]),
        ];
    }
}
