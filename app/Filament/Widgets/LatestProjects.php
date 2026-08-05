<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class LatestProjects extends BaseWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 6,
        'lg' => 3,
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
            ->where(function ($query) {
                return $query->where('owner_id', auth()->user()->id)
                    ->orWhereHas('users', function ($query) {
                        return $query->where('users.id', auth()->user()->id);
                    });
            })
            ->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('Project name'))
                ->formatStateUsing(fn ($record) => new HtmlString('
                            <div class="w-full flex items-center gap-2">
                                <div style=\'background-image: url("'.e($record->cover).'")\'
                                 class="w-8 h-8 bg-cover bg-center bg-no-repeat"></div>
                                '.e($record->name).'
                            </div>
                        ')),

            Tables\Columns\TextColumn::make('owner.name')
                ->label(__('Project owner')),

            Tables\Columns\TextColumn::make('status.name')
                ->label(__('Project status'))
                ->formatStateUsing(fn ($record) => new HtmlString('
                            <div class="flex items-center gap-2">
                                <span class="filament-tables-color-column relative flex h-6 w-6 rounded-md"
                                    style="background-color: '.e($record->status->color).'"></span>
                                <span>'.e($record->status->name).'</span>
                            </div>
                        ')),
        ];
    }
}
