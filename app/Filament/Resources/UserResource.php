<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 1;

    protected static function getNavigationLabel(): string
    {
        return __('Users');
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
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('Full name'))
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('email')
                                    ->label(__('Email address'))
                                    ->email()
                                    ->required()
                                    ->rule(
                                        fn ($record) => 'unique:users,email,'
                                            .($record ? $record->id : 'NULL')
                                            .',id,deleted_at,NULL'
                                    )
                                    ->maxLength(255),

                                Forms\Components\CheckboxList::make('roles')
                                    ->label(__('Permission roles'))
                                    ->required()
                                    ->columns(3)
                                    ->relationship('roles', 'name')
                                    // Guard: don't let the Super Admin role be
                                    // removed from the last Super Admin.
                                    ->rule(fn (?User $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (! $record || ! $record->isLastSuperAdmin()) {
                                            return;
                                        }
                                        $superAdminRoleId = User::superAdminRoleId()
                                            ?? \App\Models\Role::where('name', 'Super Admin')->value('id');
                                        $selected = array_map('strval', (array) $value);
                                        if (! in_array((string) $superAdminRoleId, $selected, true)) {
                                            $fail(__('You cannot remove the Super Admin role from the last Super Admin.'));
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
                    ->label(__('Full name'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email address'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TagsColumn::make('roles.name')
                    ->label(__('Roles'))
                    ->limit(2),

                Tables\Columns\IconColumn::make('is_super_admin')
                    ->label(__('Super Admin'))
                    ->boolean()
                    ->getStateUsing(fn (User $record) => $record->isSuperAdmin()),

                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label(__('Email verified at'))
                    ->dateTime()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('socials')
                    ->label(__('Linked social networks'))
                    ->view('partials.filament.resources.social-icon'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                // New registrants get no role until an admin assigns one; this
                // surfaces those awaiting approval so they can be actioned.
                Tables\Filters\Filter::make('pending_approval')
                    ->label(__('Pending approval'))
                    ->query(fn ($query) => $query->doesntHave('roles')),

                Tables\Filters\Filter::make('super_admins')
                    ->label(__('Super Admins only'))
                    ->query(function ($query) {
                        $roleId = User::superAdminRoleId();

                        return $query->whereHas('roles', fn ($q) => $roleId !== null
                            ? $q->whereKey($roleId)
                            : $q->where('name', 'Super Admin'));
                    }),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
