<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TicketTypeResource\Pages;
use App\Models\TicketType;
use App\Support\Colors;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Guava\FilamentIconPicker\Forms\IconPicker;
use Guava\FilamentIconPicker\Tables\IconColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TicketTypeResource extends Resource
{
    protected static ?string $model = TicketType::class;

    /**
     * The SoftDeletingScope is dropped here (not just left to TrashedFilter)
     * because row/bulk actions like RestoreAction resolve their target
     * record through this unfiltered base query, not through the table's
     * filtered query - with the scope still active a trashed row could never
     * be found to restore it. TrashedFilter still controls what the
     * *listing* shows by default.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-check';

    protected static ?int $navigationSort = 1;

    protected static function getNavigationLabel(): string
    {
        return __('Ticket types');
    }

    public static function getPluralLabel(): ?string
    {
        return static::getNavigationLabel();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Referential');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Grid::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('Type name'))
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\ColorPicker::make('color')
                                    ->label(__('Type color'))
                                    ->required()
                                    ->regex(Colors::HEX_PATTERN),

                                IconPicker::make('icon')
                                    ->label(__('Type icon'))
                                    ->required(),

                                Forms\Components\Checkbox::make('is_default')
                                    ->label(__('Default type'))
                                    ->helperText(
                                        __('If checked, this type will be automatically affected to new tickets')
                                    ),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')
                    ->label(__('Type color'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Type name'))
                    ->sortable()
                    ->searchable(),

                IconColumn::make('icon')
                    ->label(__('Type icon'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label(__('Default type'))
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
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
            'index' => Pages\ListTicketTypes::route('/'),
            'create' => Pages\CreateTicketType::route('/create'),
            'view' => Pages\ViewTicketType::route('/{record}'),
            'edit' => Pages\EditTicketType::route('/{record}/edit'),
        ];
    }
}
