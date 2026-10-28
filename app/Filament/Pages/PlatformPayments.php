<?php

namespace App\Filament\Pages;

use App\Models\BillingAuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Doku\DokuPaymentException;
use App\Services\InvoiceSyncer;
use App\Services\StatusSnapshot;
use App\Services\SyncOutcome;
use App\Support\SubscriptionPlans;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Platform Super Admin only, READ-ONLY, cross-organization view of every
 * subscription Invoice (DOKU Checkout attempt). Without it the operator can
 * only see payments by querying the database.
 *
 * Authorization is copied from {@see PlatformOrganizations}: not
 * OrganizationContext/OrganizationPolicy (both scoped to "organizations this
 * user belongs to"), but `isSuperAdmin()` via userCanAccessPage(), which
 * AuthorizesPageAccess::boot() re-runs on every Livewire request.
 *
 * Three row actions, all Super Admin only (each closure re-checks
 * userCanAccessPage() on top of boot()): "Check status" (read-only look at
 * DOKU's answer), "Sync" (applies it) and "History" (the audit trail). This
 * page never writes an Invoice or Subscription itself: both DOKU actions go
 * through {@see InvoiceSyncer}, whose only write path is
 * SubscriptionActivationService::process(), and every attributed call is
 * recorded in billing_audit_logs. A test greps this file to keep it so.
 *
 * Action results are shown as notifications / per-render modal content and
 * never kept in a public Livewire property, so no DOKU answer can end up in
 * the serialized component state. The table `$record` only carries
 * SAFE_COLUMNS, so every action re-resolves the full Invoice by id.
 *
 * Sensitive columns (doku_payment_url, doku_token_id, doku_request_id,
 * doku_reference) are never selected by the table query, so they cannot end
 * up in the HTML or in Livewire state. The page keeps no public properties
 * holding records; the summary is computed per render.
 *
 * There is no "failed" card on purpose: nothing ever writes
 * invoices.status = 'failed' (a DOKU FAILED notification is ignored for
 * Checkout because the customer may retry with another method), so such a
 * number would always read 0 and suggest "no failed payments" falsely.
 */
class PlatformPayments extends AuthorizedPage implements HasForms, Tables\Contracts\HasTable
{
    use InteractsWithForms;
    use Tables\Concerns\InteractsWithTable;

    /** @var array<string, string>|null */
    protected ?array $planNames = null;

    /** Unused: see {@see userCanAccessPage()}. */
    protected static ?string $permission = null;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $slug = 'platform/payments';

    protected static string $view = 'filament.pages.platform-payments';

    /** Window of the "paid" card. */
    private const PAID_WINDOW_DAYS = 30;

    /** Window of the "expired" card. */
    private const EXPIRED_WINDOW_DAYS = 7;

    /** Columns safe to load: everything except the doku_* ones. */
    private const SAFE_COLUMNS = [
        'id', 'organization_id', 'subscription_id', 'invoice_number', 'plan_code',
        'amount', 'currency', 'status', 'expires_at', 'paid_at', 'created_at', 'updated_at',
    ];

    public static function userCanAccessPage(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Platform');
    }

    protected static function getNavigationLabel(): string
    {
        return __('Payments');
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Payments');
    }

    // ------------------------------------------------------------ summary

    /**
     * Summary cards, all from the local database.
     *
     * @return array<int, array{label: string, value: string, note: string, tone: string, icon: string}>
     */
    public function paymentStats(): array
    {
        $paid = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('paid_at', '>=', now()->subDays(self::PAID_WINDOW_DAYS))
            ->toBase()
            ->selectRaw('count(*) as total, coalesce(sum(amount), 0) as amount')
            ->first();

        $expired = Invoice::query()
            ->where('status', Invoice::STATUS_EXPIRED)
            ->where('expires_at', '>=', now()->subDays(self::EXPIRED_WINDOW_DAYS))
            ->count();

        $number = fn (int $n): string => number_format($n, 0, ',', '.');

        return [
            [
                'label' => __('Pending'),
                'value' => $number(Invoice::query()->where('status', Invoice::STATUS_PENDING)->count()),
                'note' => __('Waiting for payment'),
                'tone' => 'yellow', 'icon' => 'clock',
            ],
            [
                'label' => __('Pending past due'),
                'value' => $number(Invoice::query()->pendingPastDue()->count()),
                'note' => __('Past the payment deadline, still pending - needs checking'),
                'tone' => 'red', 'icon' => 'exclamation',
            ],
            [
                'label' => __('Paid (last :days days)', ['days' => self::PAID_WINDOW_DAYS]),
                'value' => $number((int) $paid->total),
                'note' => self::rupiah((int) $paid->amount),
                'tone' => 'green', 'icon' => 'check-circle',
            ],
            [
                'label' => __('Expired (last :days days)', ['days' => self::EXPIRED_WINDOW_DAYS]),
                'value' => $number($expired),
                'note' => __('Not paid in time'),
                'tone' => 'blue', 'icon' => 'x-circle',
            ],
            [
                'label' => __('Paid without subscription'),
                'value' => $number(Invoice::query()->paidWithoutSubscription()->count()),
                'note' => __('Money received but no subscription linked - needs checking'),
                'tone' => 'red', 'icon' => 'shield-exclamation',
            ],
        ];
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    // -------------------------------------------------------------- table

    protected function getTableQuery(): Builder
    {
        return Invoice::query()
            ->select(self::SAFE_COLUMNS)
            ->with('organization:id,name');
    }

    protected function getDefaultTableSortColumn(): ?string
    {
        return 'id';
    }

    protected function getDefaultTableSortDirection(): ?string
    {
        return 'desc';
    }

    protected function getTableRecordsPerPageSelectOptions(): array
    {
        return [10, 25, 50];
    }

    protected function getTableHeading(): ?string
    {
        return __('Invoices');
    }

    protected function getTableDescription(): ?string
    {
        return __('Every subscription payment across all organizations. The cards and the list come from this application\'s own records; DOKU is contacted only when you press Check status or Sync.');
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return __('No invoices found');
    }

    protected function getTableEmptyStateDescription(): ?string
    {
        return __('Invoices appear here once an organization starts a checkout. Try clearing the search or filters.');
    }

    protected function getTableEmptyStateIcon(): ?string
    {
        return 'heroicon-o-credit-card';
    }

    /**
     * Row actions are the only write-ish surface here; there are no bulk
     * actions or row click. They are three icon buttons (eye / refresh /
     * clock) in the last column, each with a tooltip and an aria-label, not
     * a dropdown: a dropdown hid them. The column is sticky (see the blade) so
     * a wide table cannot scroll them out of view.
     */
    protected function getTableActions(): array
    {
        $isPending = fn (Invoice $record): bool => $record->status === Invoice::STATUS_PENDING;

        return [
            // Also offered for expired invoices (read-only): "paid at DOKU
            // but expired here" is exactly what an operator needs to see.
            Tables\Actions\Action::make('checkStatus')
                ->label(__('Check status'))
                ->iconButton()
                ->icon('heroicon-o-eye')
                ->tooltip(__('Check status'))
                ->extraAttributes(['class' => 'rk-pp__act rk-pp__act--check', 'aria-label' => __('Check status')])
                ->visible(fn (Invoice $record): bool => in_array($record->status, [Invoice::STATUS_PENDING, Invoice::STATUS_EXPIRED], true))
                ->action(fn (Invoice $record) => $this->runCheckStatus($record)),

            Tables\Actions\Action::make('syncInvoice')
                ->label(__('Sync'))
                ->iconButton()
                ->icon('heroicon-o-refresh')
                ->tooltip(__('Sync'))
                ->extraAttributes(['class' => 'rk-pp__act rk-pp__act--sync', 'aria-label' => __('Sync')])
                ->visible($isPending)
                ->requiresConfirmation()
                ->modalHeading(__('Sync invoice with DOKU'))
                ->modalSubheading(__('Asks DOKU for the payment status and applies it: a paid invoice activates the subscription, an expired one is closed. This action is recorded in the audit history.'))
                ->modalButton(__('Sync'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('Reason'))
                        ->helperText(__('Why are you syncing this invoice by hand? 10-500 characters.'))
                        ->required()
                        ->minLength(InvoiceSyncer::REASON_MIN_LENGTH)
                        ->maxLength(InvoiceSyncer::REASON_MAX_LENGTH)
                        ->rows(3)
                        ->rules([
                            fn () => function (string $attribute, $value, Closure $fail) {
                                if (mb_strlen(trim((string) $value)) < InvoiceSyncer::REASON_MIN_LENGTH) {
                                    $fail(__('The reason must be at least :min characters.', ['min' => InvoiceSyncer::REASON_MIN_LENGTH]));
                                }
                            },
                        ]),
                ])
                ->action(fn (Invoice $record, array $data) => $this->runSync($record, (string) ($data['reason'] ?? ''))),

            Tables\Actions\Action::make('history')
                ->label(__('History'))
                ->iconButton()
                ->icon('heroicon-o-clock')
                ->tooltip(__('History'))
                ->extraAttributes(['class' => 'rk-pp__act rk-pp__act--history', 'aria-label' => __('History')])
                ->modalHeading(fn (Invoice $record): string => __('Billing history').' - '.$record->invoice_number)
                ->modalContent(fn (Invoice $record) => $this->historyView($record))
                ->modalActions(fn (Tables\Actions\Action $action): array => [$action->getModalCancelAction()]),
        ];
    }

    protected function getTableActionsPosition(): ?string
    {
        return Tables\Actions\Position::AfterCells;
    }

    protected function getTableActionsColumnLabel(): ?string
    {
        return __('Actions');
    }

    // ------------------------------------------------------------ actions

    /**
     * Full, current row for a table record (which only holds SAFE_COLUMNS).
     * A tampered or vanished id gives null, never an exception.
     */
    private function resolveInvoice(Invoice $record): ?Invoice
    {
        return Invoice::query()->whereKey($record->getKey())->first();
    }

    /**
     * 1 call per invoice per 30 s PER ACTION (so a status check does not hold
     * back the sync that usually follows it, and vice versa) and 20 per
     * minute per user, one counter shared by both actions. Lives here, not in InvoiceSyncer: the syncer is also
     * the command's engine and must stay free of request-scoped state, while
     * the budget is about a human clicking. Checked BEFORE anything runs, so
     * a throttled click neither calls DOKU nor writes an audit row. Returns
     * the seconds to wait, or null when allowed (and the attempt is counted).
     */
    private function throttleRetryAfter(string $action, Invoice $invoice, User $user): ?int
    {
        $limits = [
            ['billing-'.$action.':invoice:'.$invoice->getKey(), 1, InvoiceSyncer::THROTTLE_PER_INVOICE_SECONDS],
            ['billing-action:user:'.$user->getKey(), InvoiceSyncer::THROTTLE_PER_USER_PER_MINUTE, 60],
        ];

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return null;
    }

    private function runCheckStatus(Invoice $record): void
    {
        abort_unless(static::userCanAccessPage(), 403);

        $user = auth()->user();
        $invoice = $this->resolveInvoice($record);

        if ($invoice === null || ! in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_EXPIRED], true)) {
            $this->notifyBilling(__('Invoice not found or no longer eligible'), __('Nothing was checked.'), 'warning');

            return;
        }

        if (($wait = $this->throttleRetryAfter('check', $invoice, $user)) !== null) {
            $this->notifyThrottled($wait);

            return;
        }

        try {
            $snapshot = app(InvoiceSyncer::class)->check($invoice, $user);
        } catch (DokuPaymentException) {
            $this->notifyBilling(__('Check status'), __('DOKU could not be reached, try again in a moment.'), 'danger');

            return;
        } catch (\Throwable $e) {
            Log::error('Billing action failed: check status', ['invoice_id' => $invoice->getKey(), 'exception' => $e::class]);
            $this->notifyBilling(__('Check status'), __('Something went wrong, nothing was changed. Try again later.'), 'danger');

            return;
        }

        $this->notifySnapshot($invoice, $snapshot);
    }

    private function runSync(Invoice $record, string $reason): void
    {
        abort_unless(static::userCanAccessPage(), 403);

        $user = auth()->user();
        $invoice = $this->resolveInvoice($record);

        if ($invoice === null) {
            $this->notifyBilling(__('Invoice not found or no longer eligible'), __('Nothing was synced.'), 'warning');

            return;
        }

        if (($wait = $this->throttleRetryAfter('sync', $invoice, $user)) !== null) {
            $this->notifyThrottled($wait);

            return;
        }

        try {
            $outcome = app(InvoiceSyncer::class)->sync($invoice, $user, $reason);
        } catch (InvalidArgumentException) {
            $this->notifyBilling(__('Sync'), __('The reason must be between :min and :max characters.', [
                'min' => InvoiceSyncer::REASON_MIN_LENGTH, 'max' => InvoiceSyncer::REASON_MAX_LENGTH,
            ]), 'danger');

            return;
        } catch (\Throwable $e) {
            Log::error('Billing action failed: sync', ['invoice_id' => $invoice->getKey(), 'exception' => $e::class]);
            $this->notifyBilling(__('Sync'), __('Something went wrong, nothing was changed. Try again later.'), 'danger');

            return;
        }

        [$message, $kind] = $this->outcomeMessage($outcome);

        $this->notifyBilling(__('Sync'), $message, $kind);
    }

    /**
     * @return array{0: string, 1: string} operator-facing text and notification kind
     */
    private function outcomeMessage(SyncOutcome $outcome): array
    {
        return match ($outcome->result) {
            SyncOutcome::PAID => [__('Payment confirmed, subscription activated.'), 'success'],
            SyncOutcome::EXPIRED => [__('DOKU says the invoice has expired, it was closed.'), 'success'],
            SyncOutcome::ALREADY_PROCESSED => [__('Already processed, nothing changed.'), 'info'],
            SyncOutcome::IGNORED, SyncOutcome::NOT_FORWARDED => [__('No final result at DOKU yet, the invoice stays pending.'), 'info'],
            SyncOutcome::AMOUNT_MISMATCH, SyncOutcome::REFERENCE_MISMATCH, SyncOutcome::INVOICE_MISMATCH, SyncOutcome::UNKNOWN_INVOICE => [__('DOKU data does not match the invoice, nothing was applied. Manual review needed.'), 'danger'],
            SyncOutcome::TOO_EARLY => [__('Too early, wait at least :seconds seconds after the invoice was created.', ['seconds' => InvoiceSyncer::MIN_AGE_SECONDS]), 'warning'],
            SyncOutcome::DOKU_UNREACHABLE => [__('DOKU could not be reached, try again in a moment.'), 'danger'],
            default => [__('Failed to apply, the invoice was not changed.'), 'danger'],
        };
    }

    private function notifySnapshot(Invoice $invoice, StatusSnapshot $snapshot): void
    {
        $timezone = config('app.display_timezone');
        $text = fn (?string $value): string => $value === null || $value === '' ? '-' : $this->plain($value);

        $lines = [
            __('DOKU status').': **'.$this->dokuStatusLabel($snapshot->dokuStatus).'**',
            __('DOKU amount').': '.($snapshot->dokuAmount === null ? '-' : self::rupiah($snapshot->dokuAmount))
                .' / '.__('Invoice amount').': '.self::rupiah((int) $invoice->amount)
                .($snapshot->dokuAmount !== null && $snapshot->dokuAmount !== (int) $invoice->amount ? ' - **'.__('different').'**' : ''),
            __('Channel').': '.$text($snapshot->channel).' / '.__('Service').': '.$text($snapshot->service).' / '.__('Acquirer').': '.$text($snapshot->acquirerName),
            __('Invoice number in response').': '.($snapshot->matchesInvoice ? __('matches') : '**'.__('DOES NOT MATCH').'**'),
            __('Checked at').': '.$snapshot->checkedAt->timezone($timezone)->format('d M Y H:i:s'),
        ];

        if ($snapshot->transactionAt !== null) {
            $lines[] = __('DOKU order time').': '.$snapshot->transactionAt->timezone($timezone)->format('d M Y H:i:s');
        }

        $kind = 'info';

        if (! $snapshot->matchesInvoice) {
            $kind = 'danger';
            $lines[] = '**'.__('The response is about a different invoice. Do not rely on it.').'**';
        } elseif ($snapshot->dokuStatus === 'SUCCESS' && $invoice->status === Invoice::STATUS_EXPIRED) {
            // Information only: nothing is changed for an expired invoice.
            $kind = 'warning';
            $lines[] = '**'.__('Paid at DOKU, but this invoice already expired here. Nothing was changed; handle it manually outside the system.').'**';
        } elseif ($snapshot->dokuStatus === 'SUCCESS') {
            $kind = 'warning';
            $lines[] = __('Paid at DOKU but still pending here. Use Sync to apply it.');
        }

        $this->notifyBilling(__('DOKU status check'), implode("\n\n", $lines), $kind);
    }

    private function notifyThrottled(int $seconds): void
    {
        $this->notifyBilling(__('Too many requests'), __('Too many requests, try again in :seconds seconds.', ['seconds' => $seconds]), 'warning');
    }

    private function notifyBilling(string $title, string $body, string $kind): void
    {
        $notification = Notification::make()->title($title)->body($body)->persistent();

        // Filament 2 has no "info" colour: that kind is the plain notification.
        if ($kind !== 'info') {
            $notification->{$kind}();
        }

        $notification->send();
    }

    /** DOKU-origin text is shown only after dropping everything but harmless characters (the body is rendered as markdown). */
    private function plain(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9 .\-]/', ' ', $value));
    }

    private function dokuStatusLabel(string $status): string
    {
        return match ($status) {
            'SUCCESS' => __('Paid'),
            'PENDING' => __('Waiting for payment'),
            'FAILED' => __('Failed (the customer may retry)'),
            'EXPIRED' => __('Expired'),
            'REDIRECT' => __('Customer redirected, not paid yet'),
            'TIMEOUT' => __('Timed out'),
            'REFUNDED' => __('Refunded'),
            default => __('Unknown').' ('.($this->plain($status) ?: '-').')',
        };
    }

    // ------------------------------------------------------------ history

    /**
     * Latest 50 audit rows of one invoice, computed per render and handed to
     * the view as plain strings (the reason is escaped by Blade). Rows are
     * found by invoice_id, so the history of an invoice that was deleted is
     * not reachable from this table (the rows themselves survive, with the
     * invoice_number snapshot).
     */
    private function historyView(Invoice $record): \Illuminate\Contracts\View\View
    {
        abort_unless(static::userCanAccessPage(), 403);

        $timezone = config('app.display_timezone');

        $rows = BillingAuditLog::query()
            ->where('invoice_id', $record->getKey())
            ->with('actor:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (BillingAuditLog $log) => [
                'when' => $log->created_at?->timezone($timezone)->format('d M Y H:i:s'),
                'actor' => $log->actor?->name ?? '-',
                'action' => match ($log->action) {
                    BillingAuditLog::ACTION_CHECK_STATUS => __('Check status'),
                    BillingAuditLog::ACTION_SYNC => __('Sync'),
                    default => $this->plain($log->action),
                },
                'result' => $this->historyResultLabel($log->result),
                'reason' => $log->reason,
                'status' => $log->old_status === null && $log->new_status === null
                    ? '-'
                    : ($log->old_status ?? '-').' -> '.($log->new_status ?? '-'),
            ])
            ->all();

        return view('filament.pages.partials.billing-audit-history', ['rows' => $rows]);
    }

    private function historyResultLabel(string $result): string
    {
        return match ($result) {
            SyncOutcome::PAID => __('Payment confirmed'),
            SyncOutcome::EXPIRED => __('Closed as expired'),
            SyncOutcome::ALREADY_PROCESSED => __('Already processed'),
            SyncOutcome::NOT_FORWARDED, SyncOutcome::IGNORED => __('No final result yet'),
            SyncOutcome::AMOUNT_MISMATCH, SyncOutcome::REFERENCE_MISMATCH, SyncOutcome::INVOICE_MISMATCH, SyncOutcome::UNKNOWN_INVOICE => __('Data mismatch'),
            SyncOutcome::TOO_EARLY => __('Too early'),
            SyncOutcome::DOKU_UNREACHABLE => __('DOKU unreachable'),
            SyncOutcome::APPLY_FAILED => __('Failed to apply'),
            default => $this->dokuStatusLabel($result),
        };
    }

    protected function getTableBulkActions(): array
    {
        return [];
    }

    protected function getTableRecordActionUsing(): ?Closure
    {
        return null;
    }

    protected function getTableRecordUrlUsing(): ?Closure
    {
        return null;
    }

    protected function getTableColumns(): array
    {
        $timezone = config('app.display_timezone');

        $date = fn (string $name, string $label) => Tables\Columns\TextColumn::make($name)
            ->label($label)
            ->html()
            ->formatStateUsing(function ($state) use ($timezone) {
                if ($state === null) {
                    return new HtmlString('<span class="rk-pp__muted">-</span>');
                }

                $at = Carbon::parse($state)->timezone($timezone);

                return new HtmlString('<span class="rk-pl__date"><b>'.e($at->translatedFormat('d M Y')).'</b><small>'.e($at->format('H:i:s')).'</small></span>');
            })
            ->sortable();

        return [
            Tables\Columns\TextColumn::make('invoice_number')
                ->label(__('Invoice number'))
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('organization.name')
                ->label(__('Organization'))
                ->searchable(),

            Tables\Columns\TextColumn::make('plan_code')
                ->label(__('Plan'))
                ->formatStateUsing(fn (?string $state) => $this->planLabel($state))
                ->sortable(),

            Tables\Columns\TextColumn::make('amount')
                ->label(__('Amount'))
                ->formatStateUsing(fn ($state, Invoice $record) => $record->currency === 'IDR'
                    ? self::rupiah((int) $state)
                    : number_format((int) $state, 0, ',', '.').' '.$record->currency)
                ->sortable(),

            // Soft chip (same rk-og__chip as the Organizations table), not Filament's BadgeColumn.
            Tables\Columns\TextColumn::make('status')
                ->label(__('Status'))
                ->html()
                ->formatStateUsing(fn (string $state) => new HtmlString(
                    '<span class="rk-og__chip rk-og__chip--'.match ($state) {
                        Invoice::STATUS_PENDING => 'yellow',
                        Invoice::STATUS_PAID => 'green',
                        Invoice::STATUS_FAILED => 'red',
                        default => 'gray',
                    }.'">'.e($this->statusLabel($state)).'</span>'
                ))
                ->sortable(),

            Tables\Columns\TextColumn::make('attention')
                ->label(__('Needs attention'))
                ->html()
                ->getStateUsing(fn (Invoice $record) => match ($record->attentionReason()) {
                    'past_due' => __('Past due'),
                    'paid_without_subscription' => __('No subscription'),
                    default => null,
                })
                ->formatStateUsing(fn (?string $state) => $state === null
                    ? null
                    : new HtmlString('<span class="rk-og__chip rk-og__chip--red">'.e($state).'</span>')),

            $date('created_at', __('Created at')),
            $date('expires_at', __('Expires at')),
            $date('paid_at', __('Paid at')),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('status')
                ->label(__('Status'))
                ->options([
                    Invoice::STATUS_PENDING => $this->statusLabel(Invoice::STATUS_PENDING),
                    Invoice::STATUS_PAID => $this->statusLabel(Invoice::STATUS_PAID),
                    Invoice::STATUS_EXPIRED => $this->statusLabel(Invoice::STATUS_EXPIRED),
                ]),

            Tables\Filters\SelectFilter::make('plan_code')
                ->label(__('Plan'))
                ->options(collect(array_keys($this->planNames()))
                    ->mapWithKeys(fn (string $code) => [$code => $this->planLabel($code)])
                    ->all()),

            Tables\Filters\SelectFilter::make('organization_id')
                ->label(__('Organization'))
                ->relationship('organization', 'name')
                ->searchable(),

            Tables\Filters\Filter::make('created_between')
                ->label(__('Created'))
                ->form([
                    DatePicker::make('created_from')->label(__('Created from')),
                    DatePicker::make('created_until')->label(__('Created until')),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $timezone = config('app.display_timezone');

                    return $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', Carbon::parse($d, $timezone)->startOfDay()->utc()))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<=', Carbon::parse($d, $timezone)->endOfDay()->utc()));
                }),

            Tables\Filters\Filter::make('needs_attention')
                ->label(__('Needs attention'))
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->scopes(['needsAttention'])),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_PENDING => __('Pending'),
            Invoice::STATUS_PAID => __('Paid'),
            Invoice::STATUS_EXPIRED => __('Expired'),
            default => ucfirst($status),
        };
    }

    private function planLabel(?string $code): string
    {
        $name = $code === null ? null : ($this->planNames()[$code] ?? null);

        return $name === null ? (string) $code : __($name);
    }

    /**
     * Every plan's name (inactive ones too: old invoices still point at them),
     * read once per request so a page of rows costs one query, not one per row.
     * Protected, not public: Livewire only serializes public properties.
     *
     * @return array<string, string>
     */
    private function planNames(): array
    {
        return $this->planNames ??= SubscriptionPlans::names();
    }
}
