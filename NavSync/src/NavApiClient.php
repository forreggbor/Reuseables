<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * HTTP client for the NAV Online Számla API v3.0.
 */

declare(strict_types=1);

namespace NavSync;

use ErrorHandling\ErrorHandler;

/**
 * Sends queryInvoiceDigest and queryInvoiceData requests to the NAV API.
 *
 * All requests are authenticated with SHA-512 passwordHash and SHA3-512 requestSignature.
 * Rate limiting (max 30 req/min) is enforced by sleeping 2 seconds after each call.
 */
class NavApiClient implements NavApiClientInterface
{
    private const BASE_URL     = 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3';
    private const SLEEP_US     = 2_000_000;
    private const TIMEOUT_S    = 30;

    /** Namespace of all invoiceApi.xsd elements. NAV sends it under the prefix "ns2", not as the default namespace. */
    private const NS_API = 'http://schemas.nav.gov.hu/OSA/3.0/api';
    /** Invoice data namespace of the current schema; invoices submitted before 2021 use the 2.0 one (same element names). */
    private const NS_DATA = 'http://schemas.nav.gov.hu/OSA/3.0/data';


    /** @var array{softwareId:string, softwareName:string, softwareMainVersion:string, softwareDevName:string, softwareDevContact:string} */
    private array $software;

    /**
     * @param object $company  Row from nav_companies (plain-text nav_sign_key).
     * @param array  $software NAV software descriptor — keys: softwareId, softwareName,
     *                         softwareMainVersion, softwareDevName, softwareDevContact.
     */
    public function __construct(
        private readonly object $company,
        array $software
    ) {
        $this->software = $software;
    }

    /**
     * Fetches one page of invoice digests from NAV.
     *
     * @param string $direction  'INBOUND' or 'OUTBOUND'.
     * @param string $dateFrom   ISO8601 UTC datetime, e.g. "2026-06-01T00:00:00.000Z".
     * @param string $dateTo     ISO8601 UTC datetime.
     * @param int    $page       Page number, starting at 1.
     * @return array Parsed digest rows — each element has the same keys as invoiceDigest XML elements.
     * @throws \RuntimeException On HTTP or NAV functional error.
     */
    public function queryInvoiceDigest(
        string $direction,
        string $dateFrom,
        string $dateTo,
        int    $page = 1
    ): array {
        if (!in_array($direction, ['INBOUND', 'OUTBOUND'], true)) {
            throw new \InvalidArgumentException("NavSync: Invalid direction '{$direction}'.");
        }

        $timestamp = NavAuthHelper::timestamp();
        $requestId = NavAuthHelper::requestId();

        $directionEsc = htmlspecialchars($direction, ENT_XML1);
        $dateFromEsc  = htmlspecialchars($dateFrom,  ENT_XML1);
        $dateToEsc    = htmlspecialchars($dateTo,    ENT_XML1);

        $xml = $this->buildRequestXml($timestamp, $requestId, <<<XML
    <page>{$page}</page>
    <invoiceDirection>{$directionEsc}</invoiceDirection>
    <invoiceQueryParams>
        <mandatoryQueryParams>
            <insDate>
                <dateTimeFrom>{$dateFromEsc}</dateTimeFrom>
                <dateTimeTo>{$dateToEsc}</dateTimeTo>
            </insDate>
        </mandatoryQueryParams>
    </invoiceQueryParams>
XML, 'QueryInvoiceDigestRequest');

        try {
            $responseXml = $this->post('/queryInvoiceDigest', $xml);
        } finally {
            usleep(self::SLEEP_US);
        }

        return $this->parseDigestResponse($responseXml);
    }

    /**
     * Fetches full invoice data for a single invoice.
     *
     * @param string      $invoiceNumber    The invoice number.
     * @param string      $direction        'INBOUND' or 'OUTBOUND'.
     * @param string|null $supplierTaxNumber Required for INBOUND invoices.
     * @return array Parsed invoice data — keys: supplierBankAccount, lines[], vatSummary[], totals (see parseDataResponse).
     * @throws \RuntimeException On HTTP or NAV functional error.
     */
    public function queryInvoiceData(
        string  $invoiceNumber,
        string  $direction,
        ?string $supplierTaxNumber = null
    ): array {
        if (!in_array($direction, ['INBOUND', 'OUTBOUND'], true)) {
            throw new \InvalidArgumentException("NavSync: Invalid direction '{$direction}'.");
        }

        $timestamp = NavAuthHelper::timestamp();
        $requestId = NavAuthHelper::requestId();

        $xml = $this->buildRequestXml($timestamp, $requestId, $this->invoiceNumberQueryXml($invoiceNumber, $direction, $supplierTaxNumber), 'QueryInvoiceDataRequest');

        try {
            $responseXml = $this->post('/queryInvoiceData', $xml);
        } finally {
            usleep(self::SLEEP_US);
        }

        return $this->parseDataResponse($responseXml);
    }

    /**
     * Builds the <invoiceNumberQuery> body of a queryInvoiceData request.
     *
     * NAV accepts the supplier tax number filter only on the customer side: for OUTBOUND queries it answers
     * HTTP 400 BAD_QUERY_PARAM_SUPPLIER_NOT_EXPECTED, so it is sent for INBOUND queries only.
     *
     * @param string      $invoiceNumber     The invoice number.
     * @param string      $direction         'INBOUND' or 'OUTBOUND'.
     * @param string|null $supplierTaxNumber Supplier tax number (used for INBOUND only).
     * @return string XML fragment.
     */
    private function invoiceNumberQueryXml(string $invoiceNumber, string $direction, ?string $supplierTaxNumber): string
    {
        $invoiceNumberEsc = htmlspecialchars($invoiceNumber, ENT_XML1);
        $directionEsc     = htmlspecialchars($direction, ENT_XML1);
        $supplierTag      = ($direction === 'INBOUND' && $supplierTaxNumber !== null)
            ? '<supplierTaxNumber>' . htmlspecialchars($supplierTaxNumber, ENT_XML1) . '</supplierTaxNumber>'
            : '';

        return <<<XML
    <invoiceNumberQuery>
        <invoiceNumber>{$invoiceNumberEsc}</invoiceNumber>
        <invoiceDirection>{$directionEsc}</invoiceDirection>
        {$supplierTag}
    </invoiceNumberQuery>
XML;
    }

    /**
     * Builds the common XML envelope for any NAV API request.
     *
     * @param string $timestamp ISO8601 UTC timestamp.
     * @param string $requestId Unique request ID.
     * @param string $body      Inner XML specific to the operation.
     * @param string $rootTag   Root element name, e.g. "QueryInvoiceDigestRequest".
     * @return string Complete XML request body.
     */
    private function buildRequestXml(string $timestamp, string $requestId, string $body, string $rootTag): string
    {
        $login     = $this->company->nav_login;
        $taxNumber = $this->company->tax_number;
        $passHash  = $this->company->nav_password;
        $signature = NavAuthHelper::requestSignature($requestId, $timestamp, $this->company->nav_sign_key);

        $login     = htmlspecialchars($login,     ENT_XML1);
        $taxNumber = htmlspecialchars($taxNumber, ENT_XML1);
        $passHash  = htmlspecialchars($passHash,  ENT_XML1);
        $signature = htmlspecialchars($signature, ENT_XML1);

        $softwareId      = htmlspecialchars($this->software['softwareId'], ENT_XML1);
        $softwareName    = htmlspecialchars($this->software['softwareName'], ENT_XML1);
        $softwareVersion = htmlspecialchars($this->software['softwareMainVersion'], ENT_XML1);
        $devName         = htmlspecialchars($this->software['softwareDevName'], ENT_XML1);
        $devContact      = htmlspecialchars($this->software['softwareDevContact'], ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<{$rootTag} xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common"
            xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">
    <common:header>
        <common:requestId>{$requestId}</common:requestId>
        <common:timestamp>{$timestamp}</common:timestamp>
        <common:requestVersion>3.0</common:requestVersion>
        <common:headerVersion>1.0</common:headerVersion>
    </common:header>
    <common:user>
        <common:login>{$login}</common:login>
        <common:passwordHash cryptoType="SHA-512">{$passHash}</common:passwordHash>
        <common:taxNumber>{$taxNumber}</common:taxNumber>
        <common:requestSignature cryptoType="SHA3-512">{$signature}</common:requestSignature>
    </common:user>
    <software>
        <softwareId>{$softwareId}</softwareId>
        <softwareName>{$softwareName}</softwareName>
        <softwareOperation>LOCAL_SOFTWARE</softwareOperation>
        <softwareMainVersion>{$softwareVersion}</softwareMainVersion>
        <softwareDevName>{$devName}</softwareDevName>
        <softwareDevContact>{$devContact}</softwareDevContact>
        <softwareDevCountryCode>HU</softwareDevCountryCode>
    </software>
{$body}
</{$rootTag}>
XML;
    }

    /**
     * Sends an HTTPS POST to a NAV API endpoint.
     *
     * @param string $endpoint Path, e.g. "/queryInvoiceDigest".
     * @param string $body     XML request body.
     * @return string Raw XML response body.
     * @throws \RuntimeException On curl error, non-200 HTTP response, or NAV funcErrorCode.
     */
    private function post(string $endpoint, string $body): string
    {
        $ch = curl_init(self::BASE_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/xml',
                'Accept: application/xml',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \RuntimeException("NavSync: curl error on {$endpoint}: {$curlError}");
        }

        if ($response === false || $httpCode !== 200) {
            $detail = self::summarizeError($response);
            throw new \RuntimeException("NavSync: HTTP {$httpCode} on {$endpoint}" . ($detail !== '' ? " — {$detail}" : ''));
        }

        $this->assertNoFuncError($response, $endpoint);

        return $response;
    }

    /**
     * Reduces a failed NAV response body to a short, log-safe text: "[ERROR_CODE] message" when it is a
     * NAV error document, otherwise the first characters of the body with markup and whitespace removed.
     *
     * @param string|false $body Raw response body (false when there was none).
     * @return string Empty when there is nothing to report; never longer than 300 characters.
     */
    public static function summarizeError(string|false $body): string
    {
        if ($body === false || trim($body) === '') {
            return '';
        }
        libxml_use_internal_errors(true);
        try {
            $doc  = new \SimpleXMLElement($body);
            $code = (string)($doc->xpath("//*[local-name()='errorCode']")[0] ?? '');
            $msg  = (string)($doc->xpath("//*[local-name()='message']")[0] ?? '');
            if ($code !== '') {
                return mb_substr('[' . $code . ']' . ($msg !== '' ? ' ' . $msg : ''), 0, 300);
            }
        } catch (\Throwable $e) {
            // Not XML: fall through to the plain-text summary (the caller logs the failed request itself).
            ErrorHandler::debug('[NavSync] error response is not XML, using a plain-text summary', ['reason' => $e->getMessage()]);
        } finally {
            libxml_clear_errors();
        }
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($body)));
        return mb_substr($text, 0, 300);
    }

    /**
     * Checks a NAV response for a functional error and throws if there is one.
     *
     * NAV v3 answers with <common:result><common:funcCode>OK|ERROR</common:funcCode> (plus errorCode and
     * message on ERROR); the schema has no separate "funcError" element.
     *
     * @param string $xml      Raw response XML.
     * @param string $endpoint Endpoint path (for error context).
     * @return void
     * @throws \RuntimeException On malformed XML or funcCode ERROR (message carries NAV's error code and text).
     */
    private function assertNoFuncError(string $xml, string $endpoint): void
    {
        libxml_use_internal_errors(true);
        try {
            $doc = new \SimpleXMLElement($xml);
        } catch (\Throwable $e) {
            libxml_clear_errors();
            throw new \RuntimeException("NavSync: Malformed XML response from {$endpoint}: " . $e->getMessage(), 0, $e);
        }
        libxml_clear_errors();

        $funcCode = (string)($doc->xpath("//*[local-name()='result']/*[local-name()='funcCode']")[0] ?? '');
        if ($funcCode === 'ERROR') {
            $detail = self::summarizeError($xml);
            throw new \RuntimeException("NavSync: NAV error on {$endpoint}" . ($detail !== '' ? " — {$detail}" : ''));
        }
    }

    /**
     * Parses a queryInvoiceDigest response into an array of digest rows.
     *
     * All response elements live in NAV's api namespace (elementFormDefault=qualified). NAV sends it under
     * the prefix "ns2", so neither unprefixed XPath nor plain property access finds them: rows are located
     * with a registered prefix and their fields are read through the namespace URI. The digest carries net and VAT amounts but no gross amount, so gross is
     * their sum; the completion date is NAV's invoiceDeliveryDate; insDate (UTC, trailing "Z") is
     * normalised to a plain "Y-m-d H:i:s" UTC string that fits a DATETIME column.
     *
     * @param string $xml Raw response XML.
     * @return array Each element has keys matching nav_invoices columns.
     * @throws \RuntimeException On malformed XML.
     */
    private function parseDigestResponse(string $xml): array
    {
        libxml_use_internal_errors(true);
        try {
            $doc = new \SimpleXMLElement($xml);
        } catch (\Throwable $e) {
            libxml_clear_errors();
            throw new \RuntimeException('NavSync: Malformed XML in digest response: ' . $e->getMessage(), 0, $e);
        }
        libxml_clear_errors();

        $doc->registerXPathNamespace('api', self::NS_API);

        $result = [];
        foreach ($doc->xpath('//api:invoiceDigest') ?: [] as $node) {
            $d      = $node->children(self::NS_API);
            $net    = (string)$d->invoiceNetAmount    ?: '0';
            $vat    = (string)$d->invoiceVatAmount    ?: '0';
            $netHuf = (string)$d->invoiceNetAmountHUF ?: '0';
            $vatHuf = (string)$d->invoiceVatAmountHUF ?: '0';

            $result[] = [
                'invoice_number'      => (string)$d->invoiceNumber,
                'invoice_operation'   => (string)$d->invoiceOperation,
                'invoice_category'    => (string)$d->invoiceCategory,
                'issue_date'          => (string)$d->invoiceIssueDate,
                'supplier_tax_number' => (string)$d->supplierTaxNumber,
                'supplier_name'       => (string)$d->supplierName,
                'customer_name'       => (string)$d->customerName       ?: null,
                'customer_tax_number' => (string)$d->customerTaxNumber  ?: null,
                'payment_date'        => (string)$d->paymentDate        ?: null,
                'currency'            => (string)$d->currency           ?: 'HUF',
                'net_amount'          => $net,
                'vat_amount'          => $vat,
                'gross_amount'        => bcadd($net, $vat, 2),
                'net_amount_huf'      => $netHuf,
                'vat_amount_huf'      => $vatHuf,
                'gross_amount_huf'    => bcadd($netHuf, $vatHuf, 2),
                'completion_date'     => (string)$d->invoiceDeliveryDate ?: null,
                'nav_insert_date'     => self::utcDateTime((string)$d->insDate),
            ];
        }

        return $result;
    }

    /**
     * Converts a NAV UTC timestamp ("2020-03-11T08:15:30.000Z") to "Y-m-d H:i:s" (UTC), or null.
     *
     * @param string $iso NAV timestamp.
     * @return string|null
     */
    private static function utcDateTime(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }
        $timestamp = strtotime($iso);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Parses a queryInvoiceData response and decodes the embedded invoice XML.
     *
     * invoiceData is base64; it is gzip-compressed only when compressedContentIndicator is true.
     *
     * @param string $xml Raw response XML.
     * @return array Keys: supplierBankAccount (string|null), lines (array), vatSummary (array, see parseVatSummary),
     *               totals (the invoice's net / VAT / gross in its currency and in HUF for a simplified invoice, whose digest
     *               carries none; null for any other invoice, whose digest is the source of these amounts; see simplifiedTotals).
     * @throws \RuntimeException On malformed XML, invalid base64 or a payload that cannot be decompressed.
     */
    private function parseDataResponse(string $xml): array
    {
        libxml_use_internal_errors(true);
        try {
            $doc = new \SimpleXMLElement($xml);
        } catch (\Throwable $e) {
            libxml_clear_errors();
            throw new \RuntimeException('NavSync: Malformed XML in data response: ' . $e->getMessage(), 0, $e);
        }
        libxml_clear_errors();

        $doc->registerXPathNamespace('api', self::NS_API);
        $invoiceDataB64 = trim((string)($doc->xpath('//api:invoiceDataResult/api:invoiceData')[0] ?? ''));
        if ($invoiceDataB64 === '') {
            return ['supplierBankAccount' => null, 'lines' => [], 'vatSummary' => [], 'totals' => null, 'advanceType' => null, 'referencedInvoiceNumber' => null];
        }

        $decoded = base64_decode($invoiceDataB64, true);
        if ($decoded === false) {
            throw new \RuntimeException('NavSync: invoiceData is not valid base64.');
        }
        $isCompressed = strtolower(trim((string)($doc->xpath('//api:invoiceDataResult/api:compressedContentIndicator')[0] ?? ''))) === 'true';
        $invoiceXml   = $isCompressed ? @gzdecode($decoded) : $decoded;

        if ($invoiceXml === false) {
            throw new \RuntimeException('NavSync: Failed to decompress invoiceData.');
        }

        libxml_use_internal_errors(true);
        try {
            $inv = new \SimpleXMLElement($invoiceXml);
        } catch (\Throwable $e) {
            libxml_clear_errors();
            throw new \RuntimeException('NavSync: Malformed invoice XML in invoiceData: ' . $e->getMessage(), 0, $e);
        }
        libxml_clear_errors();

        // Every SimpleXMLElement has its own XPath context: the prefix must be registered on each node
        // that is queried, not only on the document root.
        $ns    = self::dataNamespace($inv);
        $first = static function (\SimpleXMLElement $node, string $path) use ($ns): ?string {
            $node->registerXPathNamespace('d', $ns);
            $hit   = $node->xpath($path);
            $value = $hit ? trim((string)$hit[0]) : '';
            return $value !== '' ? $value : null;
        };

        // HUF invoices carry unitPrice but omit unitPriceHUF: a line whose net amount equals its HUF net amount is in HUF.
        $huf = static fn(?string $unitPrice, ?string $net, ?string $netHuf): ?string
            => ($unitPrice !== null && $net !== null && $netHuf !== null && bccomp($net, $netHuf, 2) === 0) ? $unitPrice : null;

        $bankAccount = $first($inv, '//d:supplierBankAccountNumber');

        $lines = [];
        foreach ($inv->xpath('//d:line') ?: [] as $line) {
            $percentage = $first($line, './/d:vatPercentage');
            $vatRate    = $percentage !== null
                ? rtrim(rtrim(bcmul($percentage, '100', 2), '0'), '.')
                : ($first($line, './/d:vatExemption/d:case') ?? $first($line, './/d:vatOutOfScope/d:case') ?? '');

            $row = [
                'line_number'         => (int)($first($line, './d:lineNumber') ?? 0),
                'line_description'    => $first($line, './d:lineDescription'),
                'nature_indicator'    => $first($line, './d:lineNatureIndicator'),
                'quantity'            => $first($line, './d:quantity'),
                'unit_of_measure'     => $first($line, './d:unitOfMeasure'),
                'unit_of_measure_own' => $first($line, './d:unitOfMeasureOwn'),
                'unit_price'          => $first($line, './d:unitPrice'),
                'unit_price_huf'      => $first($line, './d:unitPriceHUF') ?? $huf($first($line, './d:unitPrice'), $first($line, './/d:lineNetAmount'), $first($line, './/d:lineNetAmountHUF')),
                'net_amount'          => $first($line, './/d:lineNetAmount'),
                'net_amount_huf'      => $first($line, './/d:lineNetAmountHUF'),
                'vat_rate'            => $vatRate !== '' ? $vatRate : null,
                'vat_amount'          => $first($line, './/d:lineVatAmount'),
                'vat_amount_huf'      => $first($line, './/d:lineVatAmountHUF'),
                'gross_amount'        => $first($line, './/d:lineGrossAmountNormal'),
                'gross_amount_huf'    => $first($line, './/d:lineGrossAmountNormalHUF'),
            ];
            $line->registerXPathNamespace('d', $ns);
            $simplified = $line->xpath('./d:lineAmountsSimplified')[0] ?? null;
            if ($simplified !== null) {
                $row = self::simplifiedLineAmounts($simplified, $ns) + $row;
            }
            $lines[] = $row;
        }

        return ['supplierBankAccount' => $bankAccount, 'lines' => $lines, 'vatSummary' => self::parseVatSummary($inv, $ns), 'totals' => self::simplifiedTotals($inv, $ns)]
            + self::invoiceTypeFacts($inv, $ns);
    }

    /**
     * What the invoice data says about the invoice type (#81): the advance marking of its lines and the invoice it refers to.
     *
     * NAV marks the lines an advance affects (advanceIndicator) the same way on an advance invoice and on the final invoice that
     * settles it, and the advance reference (advancePaymentData) is optional, so ADVANCE and FINAL are told apart by a rule, not
     * by a NAV field: every line marked and no advance reference = ADVANCE; a line with an advance reference, or only some lines
     * marked = FINAL; no marked line = NONE. A final invoice whose every line is marked and carries no reference reads as ADVANCE.
     * The OSA 3.0 layout (advanceData/advanceIndicator) and the 2.0 one (advanceIndicator in the line) are both read.
     *
     * @param \SimpleXMLElement $inv Parsed invoice data.
     * @param string            $ns  Namespace URI of the invoice data.
     * @return array{advanceType:?string, referencedInvoiceNumber:?string} advanceType NONE|ADVANCE|FINAL (an invoice without any line has
     *         no marked line: NONE), null when the document is not invoice data; the reference is the original invoice of a correction
     *         or storno, else the first advance invoice.
     */
    private static function invoiceTypeFacts(\SimpleXMLElement $inv, string $ns): array
    {
        $inv->registerXPathNamespace('d', $ns);
        if (!$inv->xpath('//d:invoiceMain')) {
            return ['advanceType' => null, 'referencedInvoiceNumber' => null];
        }
        $lines = $inv->xpath('//d:line') ?: [];
        if ($lines === []) {
            return ['advanceType' => 'NONE', 'referencedInvoiceNumber' => self::firstText($inv, '//d:invoiceReference/d:originalInvoiceNumber', $ns)];
        }
        $flagged = 0;
        $settles = false;
        $advance = null;
        foreach ($lines as $line) {
            $line->registerXPathNamespace('d', $ns);
            if (strtolower((string)self::firstText($line, './/d:advanceIndicator', $ns)) === 'true') {
                $flagged++;
            }
            if ($line->xpath('.//d:advancePaymentData')) {
                $settles = true;
                $advance ??= self::firstText($line, './/d:advancePaymentData/d:advanceOriginalInvoice', $ns);
            }
        }
        $type = $settles || ($flagged > 0 && $flagged < count($lines)) ? 'FINAL' : ($flagged > 0 ? 'ADVANCE' : 'NONE');
        return ['advanceType' => $type, 'referencedInvoiceNumber' => self::firstText($inv, '//d:invoiceReference/d:originalInvoiceNumber', $ns) ?? $advance];
    }

    /**
     * Amounts of a simplified invoice line: NAV holds only the gross amount and the VAT content ratio, so VAT and net are
     * derived exactly as for the invoice summary (see splitGross), and the rate is labelled from the ratio (0.2126 -> "27").
     *
     * @param \SimpleXMLElement $amounts The line's <lineAmountsSimplified> element.
     * @param string            $ns      Namespace URI of the invoice data.
     * @return array{net_amount:?string, net_amount_huf:?string, vat_rate:string, vat_amount:?string, vat_amount_huf:?string,
     *               gross_amount:?string, gross_amount_huf:?string}
     */
    private static function simplifiedLineAmounts(\SimpleXMLElement $amounts, string $ns): array
    {
        $gross    = self::firstText($amounts, './d:lineGrossAmountSimplified', $ns);
        $grossHuf = self::firstText($amounts, './d:lineGrossAmountSimplifiedHUF', $ns);
        $content  = self::firstText($amounts, './d:lineVatRate/d:vatContent', $ns);
        [$net, $vat]       = $gross === null ? [null, null] : self::splitGross($gross, $content);
        [$netHuf, $vatHuf] = $grossHuf === null ? [null, null] : self::splitGross($grossHuf, $content);
        return ['net_amount' => $net, 'net_amount_huf' => $netHuf, 'vat_rate' => self::vatRateLabel($amounts, $ns, 'lineVatRate'),
                'vat_amount' => $vat, 'vat_amount_huf' => $vatHuf, 'gross_amount' => $gross, 'gross_amount_huf' => $grossHuf];
    }

    /**
     * The totals of a simplified invoice. Its digest carries no net or VAT amount (NAV sends none for invoiceCategory
     * SIMPLIFIED), so the invoice data is their only source: gross is the sum of the <summarySimplified> blocks, VAT and net
     * are split from it per block as in parseVatSummary, so the totals always equal the sum of the VAT summary rows.
     *
     * @param \SimpleXMLElement $inv The decoded invoice document.
     * @param string            $ns  Namespace URI of the invoice data.
     * @return array{net_amount:string, vat_amount:string, gross_amount:string, net_amount_huf:string, vat_amount_huf:string, gross_amount_huf:string}|null
     *         Null when the invoice has no simplified summary (a normal or aggregate invoice).
     */
    private static function simplifiedTotals(\SimpleXMLElement $inv, string $ns): ?array
    {
        $inv->registerXPathNamespace('d', $ns);
        $blocks = $inv->xpath('//d:summarySimplified') ?: [];
        if ($blocks === []) {
            return null;
        }
        $sum = ['net_amount' => '0', 'vat_amount' => '0', 'gross_amount' => '0', 'net_amount_huf' => '0', 'vat_amount_huf' => '0', 'gross_amount_huf' => '0'];
        foreach ($blocks as $block) {
            $content = self::firstText($block, './d:vatRate/d:vatContent', $ns);
            foreach (['' => './d:vatContentGrossAmount', '_huf' => './d:vatContentGrossAmountHUF'] as $suffix => $path) {
                $gross = bcadd(self::firstText($block, $path, $ns) ?? '0', '0', 2);
                [$net, $vat] = self::splitGross($gross, $content);
                $sum['net_amount' . $suffix]   = bcadd($sum['net_amount' . $suffix], $net, 2);
                $sum['vat_amount' . $suffix]   = bcadd($sum['vat_amount' . $suffix], $vat, 2);
                $sum['gross_amount' . $suffix] = bcadd($sum['gross_amount' . $suffix], $gross, 2);
            }
        }
        return $sum;
    }

    /**
     * Splits a gross amount of a simplified invoice by its VAT content ratio: VAT is gross x ratio rounded to 2 decimals,
     * net is gross minus VAT. Without a ratio (exempt / out of scope) the whole amount is net.
     *
     * @param string      $gross   Gross amount (decimal string).
     * @param string|null $content VAT content ratio (e.g. 0.2126 = 27/127), or null.
     * @return array{0:string, 1:string} [net, VAT] with 2 decimals.
     */
    private static function splitGross(string $gross, ?string $content): array
    {
        if ($content === null) {
            return [bcadd($gross, '0', 2), '0.00'];
        }
        $vat = self::round2(bcmul($gross, $content, 6));
        return [bcsub($gross, $vat, 2), $vat];
    }

    /**
     * The namespace the invoice document itself declares: NAV keeps invoices in the schema version they were
     * submitted with, so pre-2021 invoices are OSA/2.0/data while newer ones are OSA/3.0/data. The element
     * names used here are the same in both. Anything that is not a NAV data namespace falls back to 3.0.
     *
     * @param \SimpleXMLElement $inv The decoded invoice document.
     * @return string Namespace URI.
     */
    private static function dataNamespace(\SimpleXMLElement $inv): string
    {
        $dom = dom_import_simplexml($inv);
        $uri = $dom instanceof \DOMElement ? (string)$dom->namespaceURI : '';
        return preg_match('#^http://schemas\.nav\.gov\.hu/OSA/\d+\.\d+/data$#', $uri) === 1 ? $uri : self::NS_DATA;
    }

    /**
     * Reads the invoice-level VAT summary: net and VAT in HUF per VAT rate, exactly as NAV holds it.
     *
     * This, not the invoice lines, is the source of the VAT report: NAV makes the per-line VAT amount optional
     * (many suppliers omit it) but the summary is always present.
     * - Normal invoices: one row per <summaryByVatRate>. With a single rate the VAT stated for the whole invoice
     *   (invoiceVatAmountHUF) is used, because that is what the buyer deducts (per-rate figures can differ by rounding).
     * - Simplified invoices carry only a gross amount and a "VAT content" ratio (e.g. 0.2126 = 27/127): VAT is
     *   gross x ratio (rounded to 2 decimals) and net is gross minus VAT.
     * Rates are labelled "27", "5", ... or by their exemption / out-of-scope code ("AAM", "TAM", "ATK", ...).
     *
     * @param \SimpleXMLElement $inv The decoded invoice document.
     * @param string            $ns  Namespace URI of the invoice data (see dataNamespace()).
     * @return list<array{vat_rate:string, net_amount_huf:string, vat_amount_huf:string}>
     */
    private static function parseVatSummary(\SimpleXMLElement $inv, string $ns): array
    {
        $inv->registerXPathNamespace('d', $ns);
        $rows = [];
        $add  = static function (string $rate, string $net, string $vat) use (&$rows): void {
            $net = bcadd($net, '0', 2);
            $vat = bcadd($vat, '0', 2);
            $rows[$rate] = isset($rows[$rate])
                ? ['net' => bcadd($rows[$rate]['net'], $net, 2), 'vat' => bcadd($rows[$rate]['vat'], $vat, 2)]
                : ['net' => $net, 'vat' => $vat];
        };

        foreach ($inv->xpath('//d:summaryNormal/d:summaryByVatRate') ?: [] as $block) {
            $add(
                self::vatRateLabel($block, $ns),
                self::firstText($block, './d:vatRateNetData/d:vatRateNetAmountHUF', $ns) ?? '0',
                self::firstText($block, './d:vatRateVatData/d:vatRateVatAmountHUF', $ns) ?? '0'
            );
        }
        if (count($rows) === 1) {
            $stated = self::firstText($inv, '//d:summaryNormal/d:invoiceVatAmountHUF', $ns);
            if ($stated !== null) {
                $rows[array_key_first($rows)]['vat'] = bcadd($stated, '0', 2);
            }
        }

        foreach ($inv->xpath('//d:summarySimplified') ?: [] as $block) {
            [$net, $vat] = self::splitGross(self::firstText($block, './d:vatContentGrossAmountHUF', $ns) ?? '0', self::firstText($block, './d:vatRate/d:vatContent', $ns));
            $add(self::vatRateLabel($block, $ns), $net, $vat);
        }

        $out = [];
        foreach ($rows as $rate => $amounts) {
            $out[] = ['vat_rate' => (string)$rate, 'net_amount_huf' => $amounts['net'], 'vat_amount_huf' => $amounts['vat']];
        }
        return $out;
    }

    /**
     * Label of the VAT rate inside a summary block or a simplified line (looks at its <vatRate> / <lineVatRate> child).
     *
     * @param \SimpleXMLElement $block   A <summaryByVatRate> or <summarySimplified> element, or a line's <lineAmountsSimplified>.
     * @param string            $ns      Namespace URI of the invoice data.
     * @param string            $element Name of the rate child: vatRate in a summary, lineVatRate in a line.
     * @return string "27", "5", ... for percentages; the exemption / out-of-scope case ("AAM", "TAM", "ATK",
     *                "EUFAD37", ...); "RC" (domestic reverse charge), "MARGIN", "NOVAT", or "OTHER".
     */
    private static function vatRateLabel(\SimpleXMLElement $block, string $ns, string $element = 'vatRate'): string
    {
        $rate       = './d:' . $element;
        $percentage = self::firstText($block, $rate . '/d:vatPercentage', $ns);
        if ($percentage !== null) {
            return self::percentLabel(bcmul($percentage, '100', 6));
        }
        $content = self::firstText($block, $rate . '/d:vatContent', $ns);
        if ($content !== null) {                           // content = VAT / gross, so rate = content / (1 - content)
            return self::percentLabel(bcdiv(bcmul($content, '100', 6), bcsub('1', $content, 6), 6));
        }
        $case = self::firstText($block, $rate . '/d:vatExemption/d:case', $ns)
            ?? self::firstText($block, $rate . '/d:vatOutOfScope/d:case', $ns);
        if ($case !== null) {
            return $case;
        }
        $block->registerXPathNamespace('d', $ns);
        foreach (['vatDomesticReverseCharge' => 'RC', 'marginSchemeIndicator' => 'MARGIN', 'noVatCharge' => 'NOVAT'] as $marker => $label) {
            if ($block->xpath($rate . '/d:' . $marker)) {
                return $label;
            }
        }
        return 'OTHER';
    }

    /**
     * Formats a percentage without noise: 26.9977 -> "27", 5.000000 -> "5", 18.5 -> "18.5".
     *
     * @param string $percent Percentage as a decimal string.
     * @return string
     */
    private static function percentLabel(string $percent): string
    {
        $rounded = bcadd($percent, '0.5', 0);              // nearest integer (percent >= 0)
        if (bccomp(bcsub($percent, $rounded, 6), '0.05', 6) < 0 && bccomp(bcsub($rounded, $percent, 6), '0.05', 6) < 0) {
            return $rounded;
        }
        return rtrim(rtrim(bcadd($percent, '0', 2), '0'), '.');
    }

    /**
     * Rounds a decimal string half away from zero to 2 decimals.
     *
     * @param string $value Decimal string.
     * @return string
     */
    private static function round2(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }

    /**
     * Text of the first node matching an XPath below $node (the "d" prefix is registered on the node).
     * Every SimpleXMLElement has its own XPath context, so the prefix must be registered per node.
     *
     * @param \SimpleXMLElement $node Context node.
     * @param string            $path XPath using the "d" prefix (NAV invoice data namespace).
     * @param string            $ns   Namespace URI bound to the "d" prefix.
     * @return string|null Trimmed text, or null when absent or empty.
     */
    private static function firstText(\SimpleXMLElement $node, string $path, string $ns): ?string
    {
        $node->registerXPathNamespace('d', $ns);
        $hit   = $node->xpath($path);
        $value = $hit ? trim((string)$hit[0]) : '';
        return $value !== '' ? $value : null;
    }
}
