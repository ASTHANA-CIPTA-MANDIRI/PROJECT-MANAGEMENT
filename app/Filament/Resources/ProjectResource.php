<?php

namespace App\Filament\Resources;

use App\Exports\ProjectHoursExport;
use App\Filament\Resources\ProjectResource\Forms\ProjectForm;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Models\Project;
use App\Models\ProjectFavorite;
use App\Models\ProjectStatus;
use App\Support\BulkDeleteAuthorizer;
use App\Support\UserOptions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive';

    /**
     * Eager load the relations the table columns read (owner, status, members
     * and the cover media) so the listing runs a fixed number of queries
     * instead of one per row. The SoftDeletingScope is dropped here (not just
     * left to TrashedFilter) because row/bulk actions like RestoreAction
     * resolve their target record through this unfiltered base query, not
     * through the table's filtered query - with the scope still active a
     * trashed row could never be found to restore it. TrashedFilter still
     * controls what the *listing* shows by default.
     *
     * Also the access boundary itself, not just this page's listing: a
     * project is only reachable through the panel (list, view, edit,
     * row/bulk actions, route binding, relation managers) when the current
     * user owns it or is a member - matching Project::isAccessibleBy(). Living
     * here instead of only on ListProjects::getTableQuery() means any other
     * page or action built on this resource inherits the same scope by
     * default, rather than needing to repeat it.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->accessibleBy(auth()->user())
            ->with(['owner', 'status', 'users', 'media']);
    }

    protected static ?int $navigationSort = 1;

    protected static function getNavigationLabel(): string
    {
        return __('Projects');
    }

    public static function getPluralLabel(): ?string
    {
        return static::getNavigationLabel();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Management');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(ProjectForm::schema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('cover')
                    ->label(__('Cover image'))
                    ->formatStateUsing(fn ($state) => new HtmlString('
                            <div style=\'background-image: url("'.e($state).'")\'
                                 class="w-8 h-8 bg-cover bg-center bg-no-repeat"></div>
                        ')),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Project name'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label(__('Project owner'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status.name')
                    ->label(__('Project status'))
                    ->formatStateUsing(fn ($record) => view('components.color-badge', [
                        'color' => $record->status->color,
                        'label' => $record->status->name,
                    ]))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TagsColumn::make('users.name')
                    ->label(__('Affected users'))
                    ->limit(2),

                Tables\Columns\BadgeColumn::make('type')
                    ->enum([
                        'kanban' => __('Kanban'),
                        'scrum' => __('Scrum'),
                    ])
                    ->colors([
                        'secondary' => 'kanban',
                        'warning' => 'scrum',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('owner_id')
                    ->label(__('Owner'))
                    ->multiple()
                    ->options(fn () => UserOptions::visible()),

                Tables\Filters\SelectFilter::make('status_id')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(fn () => ProjectStatus::all()->pluck('name', 'id')->toArray()),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([

                Tables\Actions\Action::make('favorite')
                    ->label('')
                    ->icon('heroicon-o-star')
                    ->color(fn ($record) => auth()->user()->favoriteProjects()
                        ->where('projects.id', $record->id)->count() ? 'success' : 'default')
                    ->action(function ($record) {
                        $projectId = $record->id;
                        $projectFavorite = ProjectFavorite::where('project_id', $projectId)
                            ->where('user_id', auth()->user()->id)
                            ->first();
                        if ($projectFavorite) {
                            $projectFavorite->delete();
                        } else {
                            try {
                                ProjectFavorite::create([
                                    'project_id' => $projectId,
                                    'user_id' => auth()->user()->id,
                                ]);
                            } catch (\Illuminate\Database\QueryException $e) {
                                // Already favorited by an overlapping request; nothing to do.
                                if ($e->getCode() !== '23000') {
                                    throw $e;
                                }
                            }
                        }
                        Filament::notify('success', __('Project updated'));
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\RestoreAction::make(),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('exportLogHours')
                        ->label(__('Export hours'))
                        ->icon('heroicon-o-document-download')
                        ->color('secondary')
                        ->action(fn ($record) => Excel::download(
                            new ProjectHoursExport($record),
                            'time_'.Str::slug($record->name).'.csv',
                            \Maatwebsite\Excel\Excel::CSV,
                            ['Content-Type' => 'text/csv']
                        )),

                    Tables\Actions\Action::make('kanban')
                        ->label(
                            fn ($record) => ($record->type === 'scrum' ? __('Scrum board') : __('Kanban board'))
                        )
                        ->icon('heroicon-o-view-boards')
                        ->color('secondary')
                        ->url(function ($record) {
                            if ($record->type === 'scrum') {
                                return route('filament.pages.scrum/{project}', ['project' => $record->id]);
                            } else {
                                return route('filament.pages.kanban/{project}', ['project' => $record->id]);
                            }
                        }),
                ])->color('secondary'),
            ])
            ->bulkActions([
                // The shared DeleteBulkAction default (AppServiceProvider) does
                // not wrap each record's delete() in a transaction. That is a
                // no-op for most resources, but Project's cascade (tickets,
                // sprints, epics - see ProjectObserver) needs it to be atomic
                // with the project's own row, the same as the single-record
                // delete action and the API's destroy() already are.
                Tables\Actions\DeleteBulkAction::make()
                    ->using(static function (EloquentCollection $records): void {
                        $denied = 0;

                        $records->each(function (Model $record) use (&$denied): void {
                            if (! BulkDeleteAuthorizer::allows($record)) {
                                $denied++;

                                return;
                            }

                            DB::transaction(fn () => $record->delete());
                        });

                        if ($denied > 0) {
                            Notification::make()
                                ->warning()
                                ->title(__('Some records were not deleted'))
                                ->body(__(':count record(s) you are not allowed to delete were skipped.', [
                                    'count' => $denied,
                                ]))
                                ->send();
                        }
                    }),
                Tables\Actions\RestoreBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SprintsRelationManager::class,
            RelationManagers\UsersRelationManager::class,
            RelationManagers\StatusesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
