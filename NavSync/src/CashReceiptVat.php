<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * VAT arithmetic for cash register receipts.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Maps receipt lines to VAT rates and derives the per-rate VAT from gross amounts.
 */
final class CashReceiptVat
{
    /**
     * Sets each line's vat_rate from the collector → rate map; lines whose collector is not in the map keep NULL.
     *
     * @param list<array<string,mixed>>  $lines Receipt lines (keys vat_collector, vat_rate, ...).
     * @param array<string,string>|null  $map   Collector → rate, or null when the log's VAT table was unreadable.
     * @return list<array<string,mixed>> The lines with vat_rate filled where known.
     */
    public static function applyRates(array $lines, ?array $map): array
    {
        foreach ($lines as $i => $line) {
            $lines[$i]['vat_rate'] = $map[$line['vat_collector']] ?? null;
        }
        return $lines;
    }

    /**
     * Sums the mapped lines per VAT rate and derives net and VAT from the gross sum.
     *
     * Numeric rates: VAT = gross × rate / (100 + rate), rounded half away from zero to whole HUF. Any other rate
     * (AAM, TAM, ...) carries no VAT. Unmapped lines (vat_rate NULL) are skipped.
     *
     * @param list<array{gross_amount:string, vat_rate:?string}> $lines Receipt lines.
     * @return list<array{vat_rate:string, gross:string, net:string, vat:string}> One row per rate, sorted by rate.
     */
    public static function summarize(array $lines): array
    {
        $gross = [];
        foreach ($lines as $line) {
            if ($line['vat_rate'] === null) {
                continue;
            }
            $gross[$line['vat_rate']] = bcadd($gross[$line['vat_rate']] ?? '0', $line['gross_amount'], 2);
        }
        ksort($gross, SORT_STRING);

        $rows = [];
        foreach ($gross as $rate => $sum) {
            $rate = (string)$rate;
            $vat  = ctype_digit($rate) ? self::roundToWhole(bcdiv(bcmul($sum, $rate, 4), bcadd('100', $rate, 0), 6)) : '0';
            $rows[] = [
                'vat_rate' => $rate,
                'gross'    => bcadd($sum, '0', 2),
                'net'      => bcsub($sum, $vat, 2),
                'vat'      => bcadd($vat, '0', 2),
            ];
        }
        return $rows;
    }

    /**
     * Rounds a decimal string half away from zero to a whole number.
     *
     * @param string $value Decimal string with at least one fractional digit.
     * @return string Whole-number string ("-0" becomes "0").
     */
    private static function roundToWhole(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $rounded  = bcadd(ltrim($value, '-'), '0.5', 0);
        return ($negative && $rounded !== '0') ? '-' . $rounded : $rounded;
    }
}
