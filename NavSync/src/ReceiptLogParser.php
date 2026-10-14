<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Parses the XML of a cash register log file into receipts with their lines.
 */

declare(strict_types=1);

namespace NavSync;

/**
 * Reads the receipt records (NYN/NYT receipts, ESN/EST simplified invoices) out of a log file XML (AEEnaplo V2).
 *
 * Matching is by element local name, so the XML namespace of the log schema version does not matter. Records of
 * other kinds (events, closings, ...) are ignored.
 */
final class ReceiptLogParser
{
    /** Receipt record tag → [kind, practice mode]. */
    private const RECEIPT_TAGS = [
        'NYN' => ['RECEIPT', false],
        'NYT' => ['RECEIPT', true],
        'ESN' => ['SIMPLIFIED_INVOICE', false],
        'EST' => ['SIMPLIFIED_INVOICE', true],
    ];

    /** Time zone of the closing time that is stored. */
    private const LOCAL_ZONE = 'Europe/Budapest';

    /**
     * Parses a log file XML.
     *
     * @param string $xml UTF-8 XML text.
     * @return array{receipts: list<array<string,mixed>>, vat_table_text: string, vat_table_count: int} "vat_table_text" is the first INF/VAT text ('' when absent); "vat_table_count" the number of different non-empty INF/VAT texts in the file (more than 1 means the VAT table changed inside it).
     * @throws \RuntimeException On malformed XML or a receipt without its record number, number or closing time.
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new \RuntimeException('NavSync: log XML is empty');
        }
        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($xml, LIBXML_NONET)) {
                $error = libxml_get_last_error();
                throw new \RuntimeException('NavSync: log XML is malformed' . ($error !== false ? ': ' . trim($error->message) : ''));
            }
        } finally {
            if ($previous === false) {
                libxml_clear_errors();   // only drop what this call collected; a host that collects errors keeps its own
            }
            libxml_use_internal_errors($previous);   // never leave the host's libxml error mode changed
        }

        $root = $doc->documentElement;
        if ($root === null) {
            throw new \RuntimeException('NavSync: log XML has no root element');
        }

        $receipts = [];
        $vatText  = null;
        $vatTexts = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = $node->localName ?? '';
            if ($name === 'INF') {
                $text     = $this->childText($node, 'VAT') ?? '';
                $vatText ??= $text;
                if ($text !== '') {
                    $vatTexts[$text] = true;
                }
            } elseif (isset(self::RECEIPT_TAGS[$name])) {
                [$kind, $practice] = self::RECEIPT_TAGS[$name];
                $receipts[] = $this->parseReceipt($node, $kind, $practice);
            }
        }
        return ['receipts' => $receipts, 'vat_table_text' => $vatText ?? '', 'vat_table_count' => count($vatTexts)];
    }

    /**
     * Reads one receipt record.
     *
     * @param \DOMElement $record   NYN/NYT/ESN/EST element.
     * @param string      $kind     'RECEIPT' or 'SIMPLIFIED_INVOICE'.
     * @param bool        $practice True for practice-mode records.
     * @return array<string,mixed>
     * @throws \RuntimeException When RSR, NSZ or CTS is missing, empty or invalid, or any item or amount is malformed
     *                           (the message names the receipt's RSR and number; the cause is kept as previous exception).
     */
    private function parseReceipt(\DOMElement $record, string $kind, bool $practice): array
    {
        $recordNo = $this->childText($record, 'RSR');
        $number   = $this->childText($record, 'NSZ');
        $closed   = $this->childText($record, 'CTS');
        if ($recordNo === null || $recordNo === '' || !ctype_digit($recordNo)) {
            throw new \RuntimeException("NavSync: receipt record has a missing or non-numeric RSR ('" . ($recordNo ?? '') . "')");
        }
        if ($number === null || $number === '' || $closed === null || $closed === '') {
            throw new \RuntimeException("NavSync: receipt record RSR={$recordNo} is missing NSZ or CTS");
        }

        try {
            // The XSD requires a UTC offset; without one the time would be read in the PHP default zone, so refuse it.
            if (!preg_match('/(Z|[+-]\d{2}:\d{2})$/', $closed)) {
                throw new \RuntimeException("closing time '{$closed}' has no UTC offset");
            }
            $closedAt = (new \DateTimeImmutable($closed))->setTimezone(new \DateTimeZone(self::LOCAL_ZONE))->format('Y-m-d H:i:s');

            $lines = [];
            foreach ($record->childNodes as $node) {
                if ($node instanceof \DOMElement && $node->localName === 'ITL') {
                    foreach ($this->parseItems($node, count($lines) + 1) as $line) {
                        $line['line_number'] = count($lines) + 1;
                        $lines[]             = $line;
                    }
                }
            }

            return [
                'record_no'             => (int)$recordNo,
                'kind'                  => $kind,
                'practice'              => $practice,
                'receipt_number'        => $number,
                'simplified_invoice_id' => $this->childText($record, 'IID'),
                'closed_at'             => $closedAt,
                'gross_total'           => $this->amount($this->childText($record, 'SUM') ?? '0'),
                'rounding'              => $this->amount($this->childText($record, 'RND') ?? '0'),
                'interrupted'           => $this->childText($record, 'CNC') === '1',
                'lines'                 => $lines,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException("NavSync: receipt RSR={$recordNo} number={$number}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Splits one ITL element into items; a new item starts at every NA element.
     *
     * @param \DOMElement $itl         ITL element.
     * @param int         $firstLineNo Line number the first item of this element will get (for error messages).
     * @return list<array<string,mixed>> Items without a line number.
     * @throws \RuntimeException On an element before the first NA, a non-numeric amount, or an item without SU.
     */
    private function parseItems(\DOMElement $itl, int $firstLineNo): array
    {
        $items = [];
        $item  = null;
        foreach ($itl->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name   = (string)$node->localName;
            $lineNo = $firstLineNo + count($items);
            if ($name === 'NA') {
                if ($item !== null) {
                    $items[] = $this->finishItem($item, $lineNo);
                }
                $item = [
                    'description' => trim($node->textContent), 'article_no' => null, 'unit_price' => null, 'quantity' => null,
                    'unit' => null, 'item_type' => '', 'gross_amount' => null, 'vat_collector' => '', 'vat_rate' => null,
                ];
                continue;
            }
            if ($item === null) {
                throw new \RuntimeException("line {$lineNo}: element {$name} appears before the first NA");
            }
            $value = trim($node->textContent);
            try {
                switch ($name) {
                    case 'PN':
                        $item['article_no'] = $value;
                        break;
                    case 'UN':
                        $item['unit_price'] = $this->amount($value);
                        break;
                    case 'QY':
                        $item['quantity'] = $this->amount($value);
                        break;
                    case 'IU':
                        $item['unit'] = $value;
                        break;
                    case 'TY':
                        $item['item_type'] = $value;
                        break;
                    case 'SU':
                        $item['gross_amount'] = $this->amount($value);
                        break;
                    case 'VC':
                        $item['vat_collector'] = $value;
                        break;
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("line {$lineNo} ({$name}): " . $e->getMessage(), 0, $e);
            }
        }
        if ($item !== null) {
            $items[] = $this->finishItem($item, $firstLineNo + count($items));
        }
        return $items;
    }

    /**
     * Checks that an item has its mandatory gross amount (SU).
     *
     * @param array<string,mixed> $item   Item collected so far.
     * @param int                 $lineNo Line number of the item (for the error message).
     * @return array<string,mixed> The item.
     * @throws \RuntimeException When SU is missing.
     */
    private function finishItem(array $item, int $lineNo): array
    {
        if ($item['gross_amount'] === null) {
            throw new \RuntimeException("line {$lineNo}: item '{$item['description']}' has no SU");
        }
        return $item;
    }

    /**
     * Text of the first direct child with the given local name, or null when absent.
     *
     * @param \DOMElement $parent Parent element.
     * @param string      $name   Local name of the child.
     * @return string|null Trimmed text.
     */
    private function childText(\DOMElement $parent, string $name): ?string
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                return trim($node->textContent);
            }
        }
        return null;
    }

    /**
     * Converts a NAV decimal (comma separator) into a dot-decimal string.
     *
     * @param string $value NAV number text.
     * @return string Number text with a dot.
     * @throws \RuntimeException When the text is not a number.
     */
    private function amount(string $value): string
    {
        $normalised = str_replace(',', '.', trim($value));
        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalised)) {
            throw new \RuntimeException("NavSync: not a number in the log: '{$value}'");
        }
        return $normalised;
    }
}
