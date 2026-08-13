<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Permission;
use App\Models\Role;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-open';

    protected static ?int $navigationSort = 3;

    protected static function getNavigationLabel(): string
    {
        return __('Roles');
    }

    public static function getPluralLabel(): ?string
    {
        return static::getNavigationLabel();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Permissions');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Grid::make()
                            ->columns(1)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('Permission name'))
                                    ->unique(table: Permission::class, column: 'name')
                                    ->maxLength(255)
                                    ->required(),

                                Forms\Components\CheckboxList::make('permissions')
                                    ->label(__('Permissions'))
                                    ->required()
                                    ->columns(4)
                                    ->relationship('permissions', 'name')
                                    // Privilege escalation: without this, anyone holding
                                    // "Update role" could tick every permission onto a
                                    // role they already hold. Permissions the role
                                    // already has may be kept, only new ones are checked.
                                    ->rule(fn (?Role $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                        $actor = auth()->user();

                                        if ($actor?->isSuperAdmin()) {
                                            return;
                                        }

                                        $selected = array_map('strval', (array) $value);
                                        $current = $record
                                            ? $record->permissions->pluck('id')->map('strval')->all()
                                            : [];
                                        $added = array_diff($selected, $current);

                                        if ($added === []) {
                                            return;
                                        }

                                        $held = $actor
                                            ? $actor->getAllPermissions()->pluck('id')->map('strval')->all()
                                            : [];

                                        if (array_diff($added, $held) !== []) {
                                            $fail(__('You cannot grant permissions that you do not have yourself.'));
                                        }
                                    }),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Permission name'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TagsColumn::make('permissions.name')
                    ->label(__('Permissions'))
                    ->limit(2),

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('permissions');
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
