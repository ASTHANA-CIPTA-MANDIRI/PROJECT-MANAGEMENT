<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\ApiTokenIssuer;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Actions;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Lets a user issue and revoke their own API tokens without a shell.
 *
 * This is the first-party half of the token endpoints in
 * {@see \App\Http\Controllers\Api\V1\ApiTokenController}: the API refuses to
 * mint a token for a request that is itself token-authenticated, so this page
 * is where the first one comes from.
 */
class ApiTokens extends AuthorizedPage implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    /**
     * Deliberately null: the page never shows anything but the signed-in
     * user's own tokens, and a token can do no more than the user it belongs
     * to, so gating it behind a permission would only keep people from
     * revoking credentials they already hold.
     */
    protected static ?string $permission = null;

    protected static ?string $slug = 'api-tokens';

    protected static string $view = 'filament.pages.api-tokens';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 3;

    /**
     * The secret of the token just created. Held for this one render — it is
     * stored hashed and can never be shown again.
     */
    public ?string $plainTextToken = null;

    protected static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('API tokens');
    }

    protected function getTitle(): string
    {
        return __('API tokens');
    }

    protected function getActions(): array
    {
        return [
            Actions\Action::make('create')
                ->label(__('Create token'))
                ->icon('heroicon-o-plus')
                ->modalWidth('md')
                ->modalButton(__('Create token'))
                ->form([
                    TextInput::make('name')
                        ->label(__('Token name'))
                        ->required()
                        ->maxLength(255)
                        ->helperText(__('A label to recognise this token by, e.g. the script that will use it.')),

                    DateTimePicker::make('expires_at')
                        ->label(__('Expires at'))
                        ->after('now')
                        ->helperText(__('Optional. Leave empty to use the default lifetime; a token never lives longer than that.')),
                ])
                ->action(function (array $data): void {
                    $token = ApiTokenIssuer::issue(
                        Filament::auth()->user(),
                        $data['name'],
                        ($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null
                    );

                    $this->plainTextToken = $token->plainTextToken;

                    Notification::make('api_token_created')
                        ->success()
                        ->title(__('Token created'))
                        ->body(__('Copy it now: it is stored hashed and cannot be shown again.'))
                        ->send();
                }),
        ];
    }

    /**
     * Scoped to the signed-in user's tokens. Filament resolves every row
     * action against this query, so a crafted record key cannot reach another
     * account's token either.
     */
    protected function getTableQuery(): Builder
    {
        return Filament::auth()->user()->tokens()->getQuery();
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('Token name'))
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Created at'))
                ->dateTime()
                ->sortable(),

            Tables\Columns\TextColumn::make('last_used_at')
                ->label(__('Last used at'))
                ->dateTime()
                ->placeholder(__('Never'))
                ->sortable(),

            Tables\Columns\TextColumn::make('expires_at')
                ->label(__('Expires at'))
                ->dateTime()
                ->sortable()
                ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null)
                ->description(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? __('Expired') : null),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Tables\Actions\Action::make('revoke')
                ->label(__('Revoke'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalSubheading(__('Anything still using this token stops working immediately.'))
                ->action(fn ($record) => $record->delete()),
        ];
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return __('No API tokens yet');
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        return __('Create one to call the REST API on your own behalf.');
    }

    protected function getDefaultTableSortColumn(): ?string
    {
        return 'created_at';
    }

    protected function getDefaultTableSortDirection(): ?string
    {
        return 'desc';
    }
}
