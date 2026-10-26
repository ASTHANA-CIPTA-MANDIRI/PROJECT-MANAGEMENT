<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Redacted, read-only view of one DOKU Check Status answer, built by
 * {@see InvoiceSyncer::check()} for a human (Super Admin) to look at.
 *
 * Deliberately carries ONLY what is needed to decide "is this invoice
 * really paid at DOKU?". It is NOT the raw response: no signature, token,
 * payment URL, request ids or any other DOKU field is copied in, so the
 * object is safe to hand to a view or a Livewire property. Every string is
 * length-capped because it originates from a remote system.
 */
final class StatusSnapshot
{
    public function __construct(
        public readonly string $invoiceNumber,
        /** transaction.status as DOKU reports it (SUCCESS, PENDING, FAILED, ...). */
        public readonly string $dokuStatus,
        public readonly ?int $dokuAmount,
        public readonly ?string $channel,
        public readonly ?string $service,
        public readonly ?string $acquirerName,
        /** Whether order.invoice_number in the response is the invoice that was asked about. */
        public readonly bool $matchesInvoice,
        public readonly CarbonImmutable $checkedAt,
        /** Only set when DOKU's response actually carried transaction.date; null otherwise. */
        public readonly ?CarbonImmutable $transactionAt = null,
    ) {}
}
