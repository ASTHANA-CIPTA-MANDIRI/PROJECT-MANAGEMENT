<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TimesheetResource\Pages;
use App\Models\Activity;
use App\Models\TicketHour;
use App\Support\OrganizationContext;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;

class TimesheetResource extends Resource
{
    protected static ?string $model = TicketHour::class;

    protected static ?string $navigationIcon = 'heroicon-o-badge-check';

    protected static ?int $navigationSort = 4;

    protected static function getNavigationLabel(): string
    {
        return __('Timesheet');
    }

    public static function getPluralLabel(): ?string
    {
        return static::getNavigationLabel();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Timesheet');
    }

    protected static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->can('List timesheet data');
    }

    /**
     * TicketHourPolicy only lets a user view/update/delete their own logged
     * hours, so the list must not surface anyone else's rows either.
     *
     * A user who belongs to more than one Organization could still have
     * logged hours against a ticket whose project belongs to an
     * Organization other than their current context - the same
     * null-safe organization match Project::scopeAccessibleBy() applies,
     * reused here rather than duplicated as new logic (see
     * TicketHourPolicy::isWithinOrganizationContext(), which gates the
     * same rows for direct/edit access).
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $organizationId = OrganizationContext::current(auth()->user())?->id;

        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->whereHas('ticket.project', fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId))
            ->with(['user', 'activity', 'ticket']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Select::make('activity_id')
                            ->label(__('Activity'))
                            ->searchable()
                            ->reactive()
                            ->options(function ($get, $set) {
                                return Activity::query()->pluck('name', 'id')->toArray();
                            }),
                        TextInput::make('value')
                            ->label(__('Time to log'))
                            ->numeric()
                            ->required(),

                        Textarea::make('comment')
                            ->label(__('Comment'))
                            ->rows(3),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('Owner'))
                    ->sortable()
                    ->formatStateUsing(fn ($record) => view('components.user-avatar', ['user' => $record->user]))
                    ->searchable(),

                Tables\Columns\TextColumn::make('value')
                    ->label(__('Hours'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('comment')
                    ->label(__('Comment'))
                    ->limit(50)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('activity.name')
                    ->label(__('Activity'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('ticket.name')
                    ->label(__('Ticket'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListTimesheet::route('/'),
            'edit' => Pages\EditTimesheet::route('/{record}/edit'),
        ];
    }
}
