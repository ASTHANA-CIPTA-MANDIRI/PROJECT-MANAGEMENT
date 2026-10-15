<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-open';

    protected static ?int $navigationSort = 3;

    protected static function getNavigationLabel(): string
    {
        return __('Roles');
    }

    public static function getLabel(): string
    {
        return __('Role');
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
                                    ->label(__('Role name'))
                                    ->unique(
                                        table: Role::class,
                                        column: 'name',
                                        ignoreRecord: true,
                                        callback: fn (Unique $rule, ?Role $record) => $rule->where(
                                            'guard_name',
                                            $record?->guard_name ?? config('auth.defaults.guard'),
                                        ),
                                    )
                                    ->maxLength(255)
                                    ->required(),

                                Forms\Components\CheckboxList::make('permissions')
                                    ->label(__('Permissions'))
                                    ->required()
                                    ->view('forms.components.permission-groups')
                                    ->relationship('permissions', 'name')
                                    // Privilege escalation: without this, anyone holding
                                    // "Update role" could tick every permission onto a
                                    // role they already hold. Permissions the role
                                    // already has may be kept, only new ones are checked.
                                    ->rule(fn (?Role $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                        $selected = array_map('strval', (array) $value);
                                        $current = $record
                                            ? $record->permissions->pluck('id')->map('strval')->all()
                                            : [];
                                        $added = array_diff($selected, $current);

                                        if ($added === []) {
                                            return;
                                        }

                                        if (! auth()->user()?->holdsAllPermissions($added)) {
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
                    ->label(__('Role name'))
                    ->sortable()
                    ->searchable(),

                // Same chips + clickable "+N" popup as the roles column of the users table.
                Tables\Columns\TextColumn::make('permissions.name')
                    ->label(__('Permissions'))
                    ->view('partials.filament.resources.chips-popup-cell')
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->withCount('permissions')
                        ->orderBy('permissions_count', $direction)),

                Tables\Columns\TextColumn::make('users_count')
                    ->label(__('Users'))
                    ->counts('users')
                    ->sortable(),
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
