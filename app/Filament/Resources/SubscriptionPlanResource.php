<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use App\Services\PlanChangeException;
use App\Services\PlanManager;
use App\Support\PlanHistory;
use App\Support\SubscriptionPanel;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Platform Super Admin only: price and duration of the subscription plans.
 *
 * Access is SubscriptionPlanPolicy (isSuperAdmin(), every destructive ability
 * false); Filament asks the policy for the menu entry, the routes and each
 * action. Unlike the other resources this one never lets Filament write the
 * model: the pages override handleRecordCreation()/handleRecordUpdate() and the
 * row toggle calls PlanManager, which writes the change and its audit row in
 * one transaction. There is no Delete action and no bulk action - a plan is
 * only ever deactivated (invoices keep pointing at its code).
 */
class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $slug = 'platform/plans';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 99;

    protected static function getNavigationLabel(): string
    {
        return __('Plans');
    }

    public static function getLabel(): string
    {
        return __('Plan');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Plans');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Platform');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(['default' => 1, 'lg' => 12])
                ->schema([
                    Forms\Components\Section::make(__('General'))
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            // Written once on creation; on edit it is shown, never submitted.
                            Forms\Components\TextInput::make('code')
                                ->label(__('Code'))
                                ->helperText(__('Lower-case letters, digits, "_" and "-". Cannot be changed later.'))
                                ->required(fn (?Model $record): bool => $record === null)
                                ->maxLength(32)
                                ->regex(SubscriptionPlan::CODE_PATTERN)
                                ->unique(table: 'subscription_plans', column: 'code', ignoreRecord: true)
                                ->disabled(fn (?Model $record): bool => $record !== null)
                                ->dehydrated(fn (?Model $record): bool => $record === null),

                            Forms\Components\TextInput::make('name')
                                ->label(__('Name'))
                                ->required()
                                ->maxLength(PlanManager::NAME_MAX_LENGTH)
                                ->reactive()
                                ->rules(PlanManager::rulesFor('name')),

                            Forms\Components\TextInput::make('amount')
                                ->label(__('Price (IDR)'))
                                ->helperText(__('Whole rupiah, at least :min.', ['min' => SubscriptionPanel::formatRupiah((int) config('subscriptions.min_amount'))]))
                                // Display only: the field shows "Rp. 200.000" but Filament's mask keeps the
                                // form state as the bare digits ("200000"), so PlanManager is unchanged.
                                ->mask(fn (Forms\Components\TextInput\Mask $mask) => $mask->money(prefix: 'Rp. ', thousandsSeparator: '.', decimalPlaces: 0, isSigned: false))
                                ->required()
                                ->reactive()
                                ->rules(PlanManager::rulesFor('amount')),

                            Forms\Components\TextInput::make('duration_days')
                                ->label(__('Duration (days)'))
                                ->helperText(__('Between :min and :max days.', ['min' => (int) config('subscriptions.min_duration_days'), 'max' => (int) config('subscriptions.max_duration_days')]))
                                ->numeric()
                                ->required()
                                ->reactive()
                                ->rules(PlanManager::rulesFor('duration_days')),

                            Forms\Components\TextInput::make('sort_order')
                                ->label(__('Order'))
                                ->helperText(__('Small numbers are shown first (1, 2, 3, ...).'))
                                ->numeric()
                                // Next free position; the first plan gets 1. Only used on Create (Edit fills the stored value).
                                ->default(fn (): int => ((int) SubscriptionPlan::query()->max('sort_order')) + 1)
                                ->required()
                                ->reactive()
                                ->rules(PlanManager::rulesFor('sort_order')),

                            Forms\Components\Toggle::make('is_active')
                                ->label(__('Active'))
                                ->default(true)
                                ->reactive(),
                        ])
                        ->columnSpan(['lg' => 12]),

                    Forms\Components\Section::make(__('Change note'))
                        ->columns(1)
                        ->schema([
                            Forms\Components\Placeholder::make('notice')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div class="rk-notice rk-notice--warning"><span>'.e(__('Changes apply to NEW checkouts only. Invoices that already exist keep the price and duration they were created with.')).'</span></div>'
                                )),

                            Forms\Components\Placeholder::make('changes')
                                ->label(__('Changes'))
                                ->hidden(fn (?Model $record): bool => $record === null)
                                ->content(fn (Closure $get, ?Model $record): HtmlString => PlanHistory::diffHtml(
                                    $record instanceof SubscriptionPlan ? $record : null,
                                    [
                                        'name' => trim((string) $get('name')),
                                        'amount' => $get('amount'),
                                        'duration_days' => $get('duration_days'),
                                        'is_active' => (bool) $get('is_active'),
                                        'sort_order' => $get('sort_order'),
                                    ]
                                )),

                            // Not a column: handed to PlanManager and stored on the audit row.
                            Forms\Components\Textarea::make('reason')
                                ->label(__('Reason (optional)'))
                                ->helperText(__('Optional, up to 500 characters. Shown in the plan history.'))
                                ->maxLength(PlanManager::REASON_MAX_LENGTH)
                                ->rows(3),
                        ])
                        ->columnSpan(['lg' => 12]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('Code'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('Price'))
                    ->formatStateUsing(fn ($state) => SubscriptionPanel::formatRupiah((int) $state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('duration_days')
                    ->label(__('Duration'))
                    ->formatStateUsing(fn ($state) => trans_choice(':count day|:count days', (int) $state, ['count' => (int) $state]))
                    ->sortable(),

                Tables\Columns\TextColumn::make('is_active')
                    ->label(__('Status'))
                    ->html()
                    ->formatStateUsing(fn ($state) => new HtmlString(
                        '<span class="rk-og__chip rk-og__chip--'.($state ? 'green' : 'gray').'">'.e($state ? __('Active') : __('Inactive')).'</span>'
                    ))
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('Status'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Active'))
                    ->falseLabel(__('Inactive')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Closures take a nullable record: Filament also builds the action once without one.
                Tables\Actions\Action::make('toggle')
                    ->label(fn (?SubscriptionPlan $record): string => $record?->is_active === false ? __('Activate') : __('Deactivate'))
                    ->icon(fn (?SubscriptionPlan $record): string => $record?->is_active === false ? 'heroicon-o-play' : 'heroicon-o-ban')
                    ->visible(fn (?SubscriptionPlan $record): bool => $record !== null && (bool) auth()->user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (?SubscriptionPlan $record): string => ($record?->is_active === false ? __('Activate plan') : __('Deactivate plan')).' - '.$record?->code)
                    ->modalSubheading(fn (?SubscriptionPlan $record): string => $record?->is_active === false
                        ? __('An active plan can be bought again at its current price and duration.')
                        : __('A deactivated plan can no longer be bought and disappears from the billing page. Invoices already created are not affected.'))
                    ->modalButton(fn (?SubscriptionPlan $record): string => $record?->is_active === false ? __('Activate') : __('Deactivate'))
                    ->action(function (SubscriptionPlan $record): void {
                        abort_unless(auth()->user()?->can('update', $record), 403);

                        try {
                            $changed = app(PlanManager::class)->setActive(auth()->user(), $record, ! $record->is_active);
                        } catch (PlanChangeException $e) {
                            Notification::make()->title(__('Plan not changed'))->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title($changed ? __('Plan updated.') : __('Nothing was changed.'))->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
