<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketResource\Forms\TicketForm;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Support\Colors;
use App\Support\UserOptions;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 2;

    /**
     * Eager load the relations the table columns read so the listing runs a
     * fixed number of queries instead of one per row. The SoftDeletingScope
     * is dropped here (not just left to TrashedFilter) because row/bulk
     * actions like RestoreAction resolve their target record through this
     * unfiltered base query, not through the table's filtered query - with
     * the scope still active a trashed row could never be found to restore
     * it. TrashedFilter still controls what the *listing* shows by default.
     *
     * Also the access boundary itself, not just this page's listing: a ticket
     * is only reachable through the panel (list, view, edit, row/bulk
     * actions, route binding) when its owner/responsible is the current user
     * or its project is accessible to them - matching TicketPolicy::isInvolved().
     * Living here instead of only on ListTickets::getTableQuery() means any
     * other page or action built on this resource inherits the same scope by
     * default, rather than needing to repeat it.
     *
     * Uses Ticket::scopeVisibleTo() (not a hand-rolled owner/responsible/
     * project clause) specifically because the Fase 6 audit fix on that
     * scope closes a cross-Organization leak the naive version has: a
     * ticket's owner_id/responsible_id branch bypasses project membership
     * entirely, so without the scope's extra Organization-context check, a
     * user who owns tickets in more than one Organization would see all of
     * them here regardless of which Organization is currently active.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\App\Models\Ticket> $query */
        $query = parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);

        return $query
            ->visibleTo(auth()->user())
            ->with([
                'project',
                'owner' => fn ($query) => $query->withTicketsAndProjectsCounts(),
                'responsible' => fn ($query) => $query->withTicketsAndProjectsCounts(),
                'status', 'type', 'priority', 'labels',
            ]);
    }

    protected static function getNavigationLabel(): string
    {
        return __('Tickets');
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
        return $form->schema(TicketForm::schema());
    }

    /**
     * Round avatar (photo or generated initials) + bold name, shared by the
     * Owner and Responsible columns. Everything is escaped.
     */
    private static function personCell(?\App\Models\User $user): HtmlString
    {
        if (! $user) {
            return new HtmlString('<span class="rk-cell-muted">-</span>');
        }

        return new HtmlString(
            '<span class="rk-cell-person" title="'.e($user->name).'"><img src="'.e((string) $user->getAttribute('avatar_url')).'" alt="" class="rk-cell-avatar">'
            .'<span class="rk-cell-title">'.e($user->name).'</span></span>'
        );
    }

    /**
     * Calendar icon + date, with a small relative line computed purely from
     * the ticket's own due_date and status (presentation only): "Selesai"
     * (final status), "Terlambat" (before today), "Hari ini", "Besok" or
     * "N hari lagi" (red when 3 days or less).
     */
    private static function dueDateCell(Ticket $record): HtmlString
    {
        if ($record->due_date === null) {
            return new HtmlString('<span class="rk-cell-muted">-</span>');
        }

        $days = (int) now()->startOfDay()->diffInDays($record->due_date->copy()->startOfDay(), false);

        if ($record->status?->is_final) {
            [$text, $class] = [__('Completed'), 'is-done'];
        } elseif ($days < 0) {
            [$text, $class] = [__('Overdue'), 'is-overdue'];
        } elseif ($days === 0) {
            [$text, $class] = [__('Today'), 'is-soon'];
        } elseif ($days === 1) {
            [$text, $class] = [__('Tomorrow'), 'is-soon'];
        } else {
            [$text, $class] = [__(':count days left', ['count' => $days]), $days <= 3 ? 'is-soon' : ''];
        }

        $icon = '<svg class="rk-due-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>';

        return new HtmlString(
            '<span class="rk-due"><span class="rk-due-date">'.$icon.e($record->due_date->translatedFormat('d M Y')).'</span>'
            .'<span class="rk-due-rel '.$class.'">'.e($text).'</span></span>'
        );
    }

    /**
     * Small coloured pill for a ticket type: name, colour and icon come from
     * the TicketType record. A missing/unknown icon falls back to a pill
     * without icon instead of breaking the page.
     */
    private static function typePill(\App\Models\TicketType $type): string
    {
        $data = ['color' => $type->color, 'label' => $type->name, 'pill' => true];

        // Resolve the icon up front: a failed <x-icon> inside a view that is
        // already rendering would corrupt Blade's component stack.
        if (filled($type->icon)) {
            try {
                app(\BladeUI\Icons\Factory::class)->svg((string) $type->icon);
                $data['icon'] = $type->icon;
            } catch (\Throwable) {
                // unknown icon: render the pill without one
            }
        }

        return view('components.color-badge', $data)->render();
    }

    public static function tableColumns(bool $withProject = true): array
    {
        // Column order (left to right): ID, Task, Assignee, Status, Priority,
        // Due date, Project, Reporter(owner), Labels, Updated. The Type column
        // is not shown on its own: the type is the first part of the grey
        // "Type · Project" line under each task title. Presentation only; every
        // value is already loaded by getEloquentQuery().
        $columns = [
            Tables\Columns\TextColumn::make('code')
                ->label(__('ID'))
                ->sortable()
                ->searchable()
                ->formatStateUsing(fn ($record) => new HtmlString('<span class="rk-id-pill">'.e($record->code).'</span>')),

            Tables\Columns\TextColumn::make('name')
                ->label(__('Ticket name'))
                ->sortable()
                ->searchable()
                ->formatStateUsing(function ($record) {
                    // Sub-line: a small pill for the ticket type (name, colour and
                    // icon straight from the TicketType record, already eager
                    // loaded) followed by the grey project name. The pill comes
                    // from the shared colour-badge component, which validates the
                    // colour with Colors::safe() and escapes every value.
                    $typePill = $record->type ? self::typePill($record->type) : '';

                    return new HtmlString(
                        '<span class="rk-cell-title">'.e($record->name).'</span>'
                        .'<span class="rk-cell-sub">'.$typePill
                        .'<span class="rk-cell-sub-project">'.e((string) $record->project?->name).'</span></span>'
                    );
                }),

            Tables\Columns\TextColumn::make('responsible.name')
                ->label(__('Responsible'))
                ->sortable()
                ->searchable()
                ->formatStateUsing(fn ($record) => self::personCell($record->responsible)),

            Tables\Columns\TextColumn::make('status.name')
                ->label(__('Status'))
                ->formatStateUsing(fn ($record) => view('components.color-badge', [
                    'color' => $record->status->color,
                    'label' => $record->status->name,
                    'pill' => true,
                ]))
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('priority.name')
                ->label(__('Priority'))
                ->formatStateUsing(fn ($record) => view('components.color-badge', [
                    'color' => $record->priority->color,
                    'label' => $record->priority->name,
                    'pill' => true,
                ]))
                ->sortable()
                ->searchable(),

            Tables\Columns\TextColumn::make('due_date')
                ->label(__('Due date'))
                ->formatStateUsing(fn ($record) => self::dueDateCell($record))
                ->sortable(),

        ];

        if ($withProject) {
            $columns[] = Tables\Columns\TextColumn::make('project.name')
                ->label(__('Project'))
                ->sortable()
                ->searchable()
                ->formatStateUsing(function ($record) {
                    $full = (string) $record->project?->name;

                    if (! str_contains($full, ' - ')) {
                        return new HtmlString('<span class="rk-proj-main">'.e($full).'</span>');
                    }

                    [$first, $rest] = explode(' - ', $full, 2);

                    return new HtmlString(
                        '<span class="rk-proj-main">'.e($first).'</span><span class="rk-proj-sub">'.e($rest).'</span>'
                    );
                });
        }

        $columns[] = Tables\Columns\TextColumn::make('owner.name')
            ->label(__('Owner'))
            ->sortable()
            ->formatStateUsing(fn ($record) => self::personCell($record->owner))
            ->searchable();

        $columns[] = Tables\Columns\TextColumn::make('labels')
            ->label(__('Labels'))
            ->formatStateUsing(function ($record) {
                if ($record->labels->isEmpty()) {
                    return new HtmlString('<span class="rk-cell-muted">-</span>');
                }

                $html = $record->labels->take(3)
                    ->map(fn ($label) => '<span class="rk-label-pill" style="background-color: '.e(Colors::safe($label->color)).'26; color: #374151; color: color-mix(in srgb, '.e(Colors::safe($label->color)).' 45%, #000)">'.e($label->name).'</span>')
                    ->implode('');

                $more = $record->labels->count() - 3;

                return new HtmlString($html.($more > 0 ? '<span class="rk-label-more">+'.$more.'</span>' : ''));
            });

        $columns[] = Tables\Columns\TextColumn::make('updated_at')
            ->label(__('Updated'))
            ->formatStateUsing(fn ($record) => new HtmlString(
                '<span title="'.e($record->updated_at?->format('Y-m-d H:i')).'">'.e(preg_replace('/\s+yang\s+lalu/', ' lalu', (string) $record->updated_at?->diffForHumans(short: true))).'</span>'
            ))
            ->sortable();

        return $columns;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns())
            // Newest activity first, so recent / demo tickets show on page 1.
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label(__('Project'))
                    ->multiple()
                    ->options(fn () => Project::accessibleBy(auth()->user())->pluck('name', 'id')->toArray()),

                Tables\Filters\SelectFilter::make('owner_id')
                    ->label(__('Owner'))
                    ->multiple()
                    ->options(fn () => UserOptions::visible()),

                Tables\Filters\SelectFilter::make('responsible_id')
                    ->label(__('Responsible'))
                    ->multiple()
                    ->options(fn () => UserOptions::visible()),

                Tables\Filters\SelectFilter::make('status_id')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(fn () => TicketStatus::whereNull('project_id')
                        ->orWhereHas('project', fn ($query) => $query->accessibleBy(auth()->user()))
                        ->pluck('name', 'id')->toArray()),

                Tables\Filters\SelectFilter::make('type_id')
                    ->label(__('Type'))
                    ->multiple()
                    ->options(fn () => TicketType::query()->visibleTo(auth()->user())->pluck('name', 'id')->toArray()),

                Tables\Filters\SelectFilter::make('priority_id')
                    ->label(__('Priority'))
                    ->multiple()
                    ->options(fn () => TicketPriority::query()->visibleTo(auth()->user())->pluck('name', 'id')->toArray()),

                Tables\Filters\TrashedFilter::make()->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\RestoreBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'create' => Pages\CreateTicket::route('/create'),
            'view' => Pages\ViewTicket::route('/{record}'),
            'edit' => Pages\EditTicket::route('/{record}/edit'),
        ];
    }
}
