<?php

namespace App\Services;

/**
 * What {@see InvoiceSyncer::sync()} did, as a plain string constant - the
 * same convention as SubscriptionActivationService::RESULT_*, whose values
 * the first seven (plus RECOVERED / RECOVERY_TOO_OLD) mirror 1:1 so the two vocabularies never drift.
 */
final class SyncOutcome
{
    // Mapped 1:1 from SubscriptionActivationService::RESULT_*.
    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const ALREADY_PROCESSED = 'already_processed';

    public const IGNORED = 'ignored';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const REFERENCE_MISMATCH = 'reference_mismatch';

    public const UNKNOWN_INVOICE = 'unknown_invoice';

    /** An expired invoice reopened as paid by InvoiceSyncer::recoverLate(). */
    public const RECOVERED = 'recovered';

    /** Expired longer ago than subscriptions.late_recovery_max_days; nothing changed. */
    public const RECOVERY_TOO_OLD = 'recovery_too_old';

    /** recoverLate() on an invoice that is not `expired` (and not already paid); DOKU was not called. */
    public const NOT_RECOVERABLE = 'not_recoverable';

    // Decided before process() is ever reached.
    /** DOKU answered with a status we never forward (PENDING, FAILED, REDIRECT, TIMEOUT, ...). */
    public const NOT_FORWARDED = 'not_forwarded';

    /** The response was about a different invoice than the one asked for. */
    public const INVOICE_MISMATCH = 'invoice_mismatch';

    public const DOKU_UNREACHABLE = 'doku_unreachable';

    /** process() threw; its transaction rolled back and the invoice is unchanged. */
    public const APPLY_FAILED = 'apply_failed';

    /** Younger than InvoiceSyncer::MIN_AGE_SECONDS; DOKU was not called. */
    public const TOO_EARLY = 'too_early';

    /** Only these two actually change an invoice. */
    public const CHANGED = [self::PAID, self::EXPIRED, self::RECOVERED];

    public function __construct(
        public readonly string $result,
        public readonly ?string $oldStatus = null,
        public readonly ?string $newStatus = null,
        /** transaction.status DOKU reported, when it was reached. */
        public readonly ?string $dokuStatus = null,
    ) {}

    public function changedInvoice(): bool
    {
        return in_array($this->result, self::CHANGED, true);
    }
}
