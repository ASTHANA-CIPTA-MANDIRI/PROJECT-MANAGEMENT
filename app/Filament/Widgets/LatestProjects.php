<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class LatestProjects extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 4,
    ];

    public function mount(): void
    {
        self::$heading = __('Latest projects');
    }

    public static function canView(): bool
    {
        return auth()->user()->can('List projects');
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getTableQuery(): Builder
    {
        return Project::query()
            // Eager load media too: each row renders $record->cover, which
            // reads the project's media collection — without this it is one
            // extra query per row (N+1).
            ->with(['owner', 'status', 'media'])
            ->limit(5)
            ->accessibleBy(auth()->user())
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            // Project + owner share one cell (owner as a muted second line) so
            // the narrow card needs no horizontal scroll. ->wrap() lifts the
            // table body's whitespace-nowrap so long names wrap/truncate.
            Tables\Columns\TextColumn::make('name')
                ->label(__('Project name'))
                ->wrap()
                ->formatStateUsing(fn ($record) => new HtmlString('
                            <div class="flex w-full min-w-0 items-center gap-2">
                                <div style=\'background-image: url("'.e($record->cover).'")\'
                                 class="h-8 w-8 shrink-0 rounded-md bg-cover bg-center bg-no-repeat"></div>
                                <div class="flex min-w-0 flex-col">
                                    <span class="break-words font-medium leading-tight">'.e($record->name).'</span>
                                    <span class="truncate text-xs text-gray-400" title="'.e(__('Project owner')).'">'.e($record->owner?->name).'</span>
                                </div>
                            </div>
                        ')),

            Tables\Columns\TextColumn::make('status.name')
                ->label(__('Project status'))
                ->formatStateUsing(fn ($record) => view('components.color-badge', [
                    'color' => $record->status->color,
                    'label' => $record->status->name,
                    'pill' => true,
                ])),
        ];
    }
}
