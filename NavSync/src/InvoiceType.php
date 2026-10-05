<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * The kind of a NAV invoice (invoice, advance, final, correction, storno), derived from what the sync stores.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Turns the two stored facts into the kind shown to the user.
 *
 * invoice_operation comes from the NAV digest (CREATE, MODIFY, STORNO) and says correction or storno; advance_type comes from
 * the invoice lines (NONE, ADVANCE, FINAL, see NavApiClient::invoiceTypeFacts) and says advance or final. A correction or storno
 * keeps its own kind whatever it corrects. The kind is unknown (null) while a fact it needs is not known yet.
 */
final class InvoiceType
{
    public const INVOICE    = 'INVOICE';
    public const ADVANCE    = 'ADVANCE';
    public const FINAL      = 'FINAL';
    public const CORRECTION = 'CORRECTION';
    public const STORNO     = 'STORNO';

    /** The values nav_invoices.advance_type may hold. */
    public const ADVANCE_TYPES = ['NONE', 'ADVANCE', 'FINAL'];

    /** The values nav_invoices.invoice_operation may hold (NAV's invoiceOperation). */
    public const OPERATIONS = ['CREATE', 'MODIFY', 'STORNO'];

    /**
     * The kind of an invoice.
     *
     * @param string|null $operation   nav_invoices.invoice_operation (NULL = digest not seen yet).
     * @param string|null $advanceType nav_invoices.advance_type (NULL = lines not read yet).
     * @return string|null One of the kind constants, or null when it cannot be told yet.
     */
    public static function of(?string $operation, ?string $advanceType): ?string
    {
        if ($operation === 'STORNO') {
            return self::STORNO;
        }
        if ($operation === 'MODIFY') {
            return self::CORRECTION;
        }
        if ($operation !== 'CREATE') {
            return null;   // the digest decides between a plain invoice and a correction or storno: unknown until it was seen
        }
        return match ($advanceType) {
            'NONE'    => self::INVOICE,
            'ADVANCE' => self::ADVANCE,
            'FINAL'   => self::FINAL,
            default   => null,
        };
    }
}
