<?php

declare(strict_types=1);

namespace SzamlazzHuAgent;

use SzamlazzHuAgent\Adapters\FileSystemStorage;
use SzamlazzHuAgent\Contracts\StorageInterface;

/**
 * SzamlazzHuAgent - Framework-agnostic Szamlazz.hu invoice integration
 *
 * Wraps the official szamlaagent SDK for easy invoice generation.
 *
 * @example
 * $agent = new SzamlazzHuAgent([
 *     'api_key' => 'your-api-key',
 *     'storage_path' => '/path/to/invoices',
 *     'seller' => [
 *         'bank_name' => 'Bank Name',
 *         'bank_account' => '12345678-12345678',
 *     ],
 * ]);
 *
 * $result = $agent->generateInvoice($orderData, $buyerData, $items);
 */
class SzamlazzHuAgent
{
    private const API_URL = 'https://www.szamlazz.hu/szamla/';

    private array $config;
    private StorageInterface $storage;
    private InvoiceBuilder $builder;
    private bool $sdkLoaded = false;

    /**
     * Initialize the Szamlazz.hu agent
     *
     * @param array $config Configuration:
     *   - api_key: string (required) - Szamlazz.hu API key
     *   - storage_path: string (required) - Path to store invoice PDFs
     *   - seller: array - Seller information (bank_name, bank_account)
     *   - vat_rate: int - Default VAT rate (default: 27)
     *   - invoice_prefix: string - Invoice number prefix
     *   - default_language: string - Default language (hu or en)
     *   - payment_methods: array - Custom payment method mapping @deprecated Use payment_method_label in $orderData instead
     *   - log_callback: callable - Logging callback fn(string $message, string $level)
     *   - storage_adapter: StorageInterface - Custom storage adapter
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->config = array_merge([
            'vat_rate' => 27,
            'default_language' => 'hu',
            'invoice_prefix' => '',
            'payment_methods' => [],
        ], $config);

        // Initialize storage - PDFs go to storage_path/pdf/
        if (isset($config['storage_adapter']) && $config['storage_adapter'] instanceof StorageInterface) {
            $this->storage = $config['storage_adapter'];
        } else {
            $pdfPath = rtrim($config['storage_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pdf';
            $this->storage = new FileSystemStorage($pdfPath);
        }

        // Initialize builder
        $this->builder = new InvoiceBuilder($this->config);

        // Load SDK
        $this->loadSdk();
    }

    /**
     * Validate required configuration
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['api_key'])) {
            throw new \InvalidArgumentException('api_key is required');
        }

        if (empty($config['storage_path']) && !isset($config['storage_adapter'])) {
            throw new \InvalidArgumentException('storage_path or storage_adapter is required');
        }
    }

    /**
     * Load the szamlaagent SDK
     */
    private function loadSdk(): void
    {
        $autoloadPath = __DIR__ . '/szamlaagent/examples/autoload.php';

        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            $this->sdkLoaded = true;

            // Configure SDK storage base path (for logs, cookies, xmls, etc.)
            if (!empty($this->config['storage_path'])) {
                \SzamlaAgent\SzamlaAgentUtil::setBasePath(
                    rtrim($this->config['storage_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                );
            }
        }
    }

    /**
     * Get SzamlaAgent API instance
     */
    private function getAgent(): ?\SzamlaAgent\SzamlaAgentAPI
    {
        if (!$this->sdkLoaded) {
            return null;
        }

        try {
            $agent = \SzamlaAgent\SzamlaAgentAPI::create(
                $this->config['api_key'],
                true,
                \SzamlaAgent\Log::LOG_LEVEL_WARN,
                \SzamlaAgent\Response\SzamlaAgentResponse::RESULT_AS_TEXT
            );

            // The request XML contains the Agent key (<szamlaagentkulcs>) — never persist it to disk
            $agent->setRequestXmlFileSave(false);

            return $agent;
        } catch (\Exception $e) {
            $this->log('Failed to create SzamlaAgent: ' . $e->getMessage(), 'ERROR');
            return null;
        }
    }

    /**
     * What a failed call means for the document. Nothing was sent, the XML could not be built, or Számlázz.hu answered
     * with its own error code: a refusal (error result, code kept; the document was not created). Any other failure after
     * sending (transport error, timeout, empty or unreadable answer, maintenance, missing PDF): uncertain, the document
     * may exist. Known limitation: a failure the SDK raises while building the request XML, before sending, is not recognised
     * (the SDK does not wrap it) and counts as uncertain; that is the safe direction (see the README, Reusables #50).
     *
     * @param \Throwable $e    The failure.
     * @param bool       $sent Whether the request had been handed to the SDK for sending.
     * @return InvoiceResult
     */
    public static function classifyFailure(\Throwable $e, bool $sent): InvoiceResult
    {
        $message = $e->getMessage();
        if (!$sent || str_starts_with($message, \SzamlaAgent\SzamlaAgentException::XML_DATA_BUILD_FAILED)) {
            return InvoiceResult::error($message);
        }
        if (preg_match('/^' . preg_quote(\SzamlaAgent\SzamlaAgentException::AGENT_ERROR, '/') . ': \[(\d+)\]/u', $message, $m) === 1) {
            return InvoiceResult::error($message, (int)$m[1]);
        }
        return InvoiceResult::uncertain($message);
    }

    /**
     * Validate connection to Szamlazz.hu
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function validateConnection(): array
    {
        try {
            $ch = curl_init(self::API_URL);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/xml',
                    'szlahu_key: ' . $this->config['api_key'],
                ],
                CURLOPT_POSTFIELDS => '<?xml version="1.0" encoding="UTF-8"?><xmlszamlaagenttest/>',
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_close($ch);

            if ($curlError) {
                return [
                    'success' => false,
                    'message' => 'cURL error: ' . $curlError,
                ];
            }

            // Check for authentication errors
            if (strpos($response, 'hibakod') !== false) {
                $errorCode = $this->extractErrorCode($response);

                if (in_array($errorCode, [3, 49, 50, 51])) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed. Please check your API key.',
                    ];
                }
            }

            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Connection validated successfully.',
                ];
            }

            if ($httpCode === 401 || $httpCode === 403) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed. Please check your API key.',
                ];
            }

            return [
                'success' => false,
                'message' => 'HTTP error: ' . $httpCode,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Query a Hungarian taxpayer by tax number through the Szamlazz.hu Agent taxpayer interface.
     *
     * Szamlazz.hu forwards the query to the NAV Online Szamla system, so the answer is limited to what NAV returns there:
     * validity, full name, short name (when NAV has one), tax number details (VAT code, county code), business type
     * (incorporation) and the address list. The official Szamlazz.hu docs sample still shows the older NAV v2.0 shape without
     * short name/incorporation/county code, while the live answer is NAV v3.0 — NAV may omit any of these and may change the
     * interface at any time, so callers must tolerate missing parts. There is no company registry number, no contact data.
     *
     * Only the first 8 digits (torzsszam) are sent. A non-singleton agent is used with file saving disabled (the answer
     * contains personal data of sole proprietors) so the call never changes state shared with invoicing.
     *
     * @param string $taxNumber Tax number in any format; the first 8 digits are used.
     * @return array{
     *     success: bool,
     *     valid: bool,
     *     error_code: int|null,
     *     message: string,
     *     taxpayer: array<string, mixed>|null,
     *     raw_xml: string|null
     * } success = NAV/Szamlazz.hu answered; valid = NAV knows the tax number (taxpayerValidity); taxpayer = null unless
     *   valid; raw_xml = the raw NAV answer (success path only, null on errors).
     */
    public function queryTaxpayer(string $taxNumber): array
    {
        $taxId = substr((string)preg_replace('/\D/', '', $taxNumber), 0, 8);
        if (strlen($taxId) !== 8) {
            return self::taxpayerResult(false, false, null, 'Invalid tax number: the first 8 digits are required.');
        }

        if (!$this->sdkLoaded) {
            return self::taxpayerResult(false, false, null, 'Taxpayer query requires the szamlaagent SDK.');
        }

        try {
            // Non-singleton: the SDK caches singleton agents per key and getTaxPayer() switches the response type on the
            // cached instance, which would leak into later invoice calls in the same process.
            $agent = \SzamlaAgent\SzamlaAgentAPI::create(
                $this->config['api_key'],
                false,
                \SzamlaAgent\Log::LOG_LEVEL_WARN,
                \SzamlaAgent\Response\SzamlaAgentResponse::RESULT_AS_TEXT,
                '',
                false
            );
            $this->noFiles($agent);
            $agent->setRequestTimeout(15);

            $response = $agent->getTaxPayer($taxId);
            $rawXml = $response->getTaxPayerData();

            if (!is_string($rawXml) || trim($rawXml) === '') {
                $this->log('Taxpayer query returned an empty answer.', 'ERROR');
                return self::taxpayerResult(false, false, null, 'Taxpayer query returned an empty answer.');
            }

            return $this->parseTaxpayerXml($rawXml);
        } catch (\Throwable $e) {
            // The SDK throws "AGENT_ERROR: [code], message" when funcCode=ERROR (e.g. 57 = malformed tax number); the raw
            // XML does not exist on that path.
            $code = null;
            if (preg_match('/^' . preg_quote(\SzamlaAgent\SzamlaAgentException::AGENT_ERROR, '/') . ': \[(\d+)\]/u', $e->getMessage(), $m) === 1) {
                $code = (int)$m[1];
            }
            $this->log('Taxpayer query failed: ' . $e->getMessage(), 'ERROR');
            return self::taxpayerResult(false, false, $code, $e->getMessage());
        }
    }

    /**
     * Parse a NAV QueryTaxpayerResponse into the structured taxpayer result (namespace/version tolerant).
     *
     * @param string $rawXml The raw NAV answer.
     * @return array The queryTaxpayer() result array.
     */
    private function parseTaxpayerXml(string $rawXml): array
    {
        // The answer comes from a third party: refuse DTDs/entities outright, never touch the network.
        if (stripos($rawXml, '<!DOCTYPE') !== false || stripos($rawXml, '<!ENTITY') !== false) {
            $this->log('Taxpayer answer rejected: it contains a DTD.', 'ERROR');
            return self::taxpayerResult(false, false, null, 'Taxpayer answer rejected: unexpected DTD.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($rawXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                $this->log('Taxpayer answer is not valid XML.', 'ERROR');
                return self::taxpayerResult(false, false, null, 'Taxpayer answer is not valid XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xp = new \DOMXPath($doc);
        $text = static function (string $query, ?\DOMNode $ctx = null) use ($xp): ?string {
            $nodes = $xp->query($query, $ctx);
            if ($nodes === false || $nodes->length === 0) {
                return null;
            }
            $value = trim((string)$nodes->item(0)->textContent);
            return $value === '' ? null : $value;
        };

        $funcCode = $text('//*[local-name()="result"]/*[local-name()="funcCode"]');
        if ($funcCode !== null && strtoupper($funcCode) !== 'OK') {
            $code = $text('//*[local-name()="result"]/*[local-name()="errorCode"]');
            $message = $text('//*[local-name()="result"]/*[local-name()="message"]') ?? 'Taxpayer query failed.';
            $this->log('Taxpayer query answered with an error: ' . $message, 'ERROR');
            return self::taxpayerResult(false, false, $code !== null ? (int)$code : null, $message, $rawXml);
        }

        $validity = $text('//*[local-name()="taxpayerValidity"]');
        $valid = $validity !== null && strtolower($validity) === 'true';

        if (!$valid || $text('//*[local-name()="taxpayerData"]/*[local-name()="taxpayerName"]') === null) {
            return self::taxpayerResult(true, false, null, 'Tax number not found.', $rawXml);
        }

        $addresses = [];
        $items = $xp->query('//*[local-name()="taxpayerAddressItem"]');
        if ($items !== false) {
            foreach ($items as $item) {
                $addr = $xp->query('./*[local-name()="taxpayerAddress"]', $item)->item(0);
                if ($addr === null) {
                    continue;
                }
                $addresses[] = [
                    'type' => $text('./*[local-name()="taxpayerAddressType"]', $item),
                    'country_code' => $text('./*[local-name()="countryCode"]', $addr),
                    'region' => $text('./*[local-name()="region"]', $addr),
                    'postal_code' => $text('./*[local-name()="postalCode"]', $addr),
                    'city' => $text('./*[local-name()="city"]', $addr),
                    'street_name' => $text('./*[local-name()="streetName"]', $addr),
                    'public_place_category' => $text('./*[local-name()="publicPlaceCategory"]', $addr),
                    'number' => $text('./*[local-name()="number"]', $addr),
                    'building' => $text('./*[local-name()="building"]', $addr),
                    'staircase' => $text('./*[local-name()="staircase"]', $addr),
                    'floor' => $text('./*[local-name()="floor"]', $addr),
                    'door' => $text('./*[local-name()="door"]', $addr),
                    'lot_number' => $text('./*[local-name()="lotNumber"]', $addr),
                ];
            }
        }

        $taxpayer = [
            'name' => $text('//*[local-name()="taxpayerData"]/*[local-name()="taxpayerName"]'),
            'short_name' => $text('//*[local-name()="taxpayerData"]/*[local-name()="taxpayerShortName"]'),
            'incorporation' => $text('//*[local-name()="taxpayerData"]/*[local-name()="incorporation"]'),
            'tax_id' => $text('//*[local-name()="taxNumberDetail"]/*[local-name()="taxpayerId"]'),
            'vat_code' => $text('//*[local-name()="taxNumberDetail"]/*[local-name()="vatCode"]'),
            'county_code' => $text('//*[local-name()="taxNumberDetail"]/*[local-name()="countyCode"]'),
            'info_date' => $text('//*[local-name()="infoDate"]'),
            'addresses' => $addresses,
        ];

        return self::taxpayerResult(true, true, null, 'OK', $rawXml, $taxpayer);
    }

    /**
     * Build the queryTaxpayer() result array.
     *
     * @param bool        $success  NAV/Szamlazz.hu answered.
     * @param bool        $valid    NAV knows the tax number.
     * @param int|null    $code     Szamlazz.hu/NAV error code, if any.
     * @param string      $message  Human-readable (English) message.
     * @param string|null $rawXml   Raw NAV answer, success path only.
     * @param array|null  $taxpayer Parsed taxpayer data, valid answers only.
     * @return array The result array documented on queryTaxpayer().
     */
    private static function taxpayerResult(bool $success, bool $valid, ?int $code, string $message, ?string $rawXml = null, ?array $taxpayer = null): array
    {
        return [
            'success' => $success,
            'valid' => $valid,
            'error_code' => $code,
            'message' => $message,
            'taxpayer' => $taxpayer,
            'raw_xml' => $rawXml,
        ];
    }

    /**
     * Generate an invoice
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Invoice line items
     * @return InvoiceResult
     */
    public function generateInvoice(array $orderData, array $buyerData, array $items): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return $this->generateInvoiceWithCurl($orderData, $buyerData, $items);
        }

        return $this->generateInvoiceWithAgent($agent, $orderData, $buyerData, $items);
    }

    /**
     * Generate invoice preview (no actual invoice created)
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Invoice line items
     * @return InvoiceResult
     */
    public function generatePreview(array $orderData, array $buyerData, array $items): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Preview requires the szamlaagent SDK.');
        }

        try {
            $agent->setPdfFileSave(false);

            $invoice = $this->builder->build($orderData, $buyerData, $items, preview: true);
            $result = $agent->generateInvoice($invoice);

            if ($result->isSuccess()) {
                $pdfContent = $result->getPdfFile();

                return new InvoiceResult(
                    success: true,
                    invoiceNumber: 'PREVIEW',
                    pdfContent: base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Preview generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Preview generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Preview generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Issues an invoice from exact net amounts, or with $preview only asks Számlázz.hu for the preview PDF (nothing is
     * created). A real invoice carries $header['external_id'] (szamlaKulsoAzon), so findInvoiceByExternalId() can tell
     * later whether it exists. Nothing is written to disk: no request/response XML, no PDF file.
     *
     * @param array<string,mixed>       $header  See InvoiceBuilder::buildNet(), plus external_id (required unless preview).
     * @param array<string,mixed>       $buyer   See InvoiceBuilder::buildNet().
     * @param list<array<string,mixed>> $items   See InvoiceBuilder::buildNet().
     * @param bool                      $preview Preview only.
     * @return InvoiceResult Success (invoice number or 'PREVIEW', pdfContent base64), refused (error, nothing was created)
     *                       or uncertain (sent, the outcome is unknown).
     */
    public function issueInvoice(array $header, array $buyer, array $items, bool $preview = false): InvoiceResult
    {
        try {
            $externalId = trim((string)($header['external_id'] ?? ''));
            if (!$preview && $externalId === '') {
                throw new \InvalidArgumentException('An invoice needs its external id');
            }
            $invoice = $this->builder->buildNet($header, $buyer, $items, $preview);
            $agent = $this->getAgent() ?? throw new \RuntimeException('The szamlaagent SDK is not available');
            $this->noFiles($agent);
            $agent->getSetting()->setInvoiceExternalId($preview ? '' : $externalId);   // the agent is a per-key singleton: always set
        } catch (\Throwable $e) {
            $this->log('Invoice request not sent: ' . $e->getMessage(), 'ERROR');
            return self::classifyFailure($e, false);
        }
        try {
            $response = $agent->generateInvoice($invoice);
        } catch (\Throwable $e) {
            $this->log('Invoice request failed: ' . $e->getMessage(), 'ERROR');
            return self::classifyFailure($e, true);
        }
        $pdf    = (string)$response->getPdfFile();
        $real   = trim((string)$response->getDocumentNumber());
        if ($preview && $real !== '') {
            // a preview must never create an invoice: say so loudly instead of hiding the number behind 'PREVIEW'
            $this->log('A preview request was answered with a real invoice number: ' . $real, 'ERROR');
            return InvoiceResult::error('Számlázz.hu answered the preview with invoice number ' . $real);
        }
        $number = $preview ? 'PREVIEW' : $real;
        if ($pdf === '' || $number === '') {
            $this->log('Invoice answer without number or PDF', 'ERROR');
            return $preview ? InvoiceResult::error('Számlázz.hu returned no preview PDF') : InvoiceResult::uncertain('Számlázz.hu answered without the invoice number or PDF');
        }
        return new InvoiceResult(success: true, invoiceNumber: $number, pdfContent: base64_encode($pdf));
    }

    /**
     * The invoice issued with an external id (szamlaKulsoAzon). Found: success with the invoice number and pdfContent
     * base64. Not found: an error result, only when Számlázz.hu's error code is in $notFoundCodes. Anything else, a network
     * error or another code included, is uncertain and never reads as "not found" (the SDK's isExistsInvoiceByExternalId()
     * treats every exception as "not found", so it is not used).
     *
     * @param string    $externalId    External id the invoice was issued with.
     * @param list<int> $notFoundCodes Számlázz.hu error codes that mean "no such invoice".
     * @return InvoiceResult
     */
    public function findInvoiceByExternalId(string $externalId, array $notFoundCodes): InvoiceResult
    {
        if (trim($externalId) === '') {
            return InvoiceResult::error('The external id is empty');
        }
        try {
            $agent = $this->getAgent() ?? throw new \RuntimeException('The szamlaagent SDK is not available');
            $this->noFiles($agent);
            $response = $agent->getInvoicePdf($externalId, \SzamlaAgent\Document\Invoice\Invoice::FROM_INVOICE_EXTERNAL_ID);
        } catch (\Throwable $e) {
            $result = self::classifyFailure($e, true);
            if (!$result->isUncertain() && $result->errorCode !== null && in_array($result->errorCode, $notFoundCodes, true)) {
                return $result;
            }
            $this->log('Invoice lookup by external id failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::uncertain($e->getMessage(), $result->errorCode);   // keeps a Számlázz.hu code that is not a not-found code
        }
        $pdf    = (string)$response->getPdfFile();
        $number = trim((string)$response->getDocumentNumber());
        if ($pdf === '' || $number === '') {
            return InvoiceResult::uncertain('Számlázz.hu answered the lookup without number or PDF');
        }
        return new InvoiceResult(success: true, invoiceNumber: $number, pdfContent: base64_encode($pdf));
    }

    /**
     * Stops the SDK from writing request/response XML (which carries the key) and PDF files for this agent.
     *
     * @param \SzamlaAgent\SzamlaAgentAPI $agent Agent.
     * @return void
     */
    private function noFiles(\SzamlaAgent\SzamlaAgentAPI $agent): void
    {
        $agent->setXmlFileSave(false);
        $agent->setRequestXmlFileSave(false);
        $agent->setResponseXmlFileSave(false);
        $agent->setPdfFileSave(false);
    }

    /**
     * Create a storno (reverse) invoice
     *
     * @param string $invoiceNumber Original invoice number to reverse
     * @param string|null $reason Reason for cancellation
     * @param bool $eInvoice Whether the reverse invoice should be electronic; should mirror
     *                       the original invoice's type (defaults to paper)
     * @return InvoiceResult
     */
    public function createStornoInvoice(string $invoiceNumber, ?string $reason = null, bool $eInvoice = false): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Storno requires the szamlaagent SDK.');
        }

        try {
            $reverseInvoice = $this->builder->buildReverseInvoice($invoiceNumber, $eInvoice);

            $result = $agent->generateReverseInvoice($reverseInvoice);

            if ($result->isSuccess()) {
                $stornoNumber = $result->getDocumentNumber();
                $pdfContent = $result->getPdfFile();

                // Save PDF
                $filename = 'storno_' . $stornoNumber . '.pdf';
                $pdfPath = $this->storage->save($filename, $pdfContent);

                $this->log("Storno invoice generated: {$stornoNumber} for original: {$invoiceNumber}", 'INFO');

                return InvoiceResult::success(
                    $stornoNumber,
                    $pdfPath,
                    base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Storno generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Storno generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Storno generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a delivery note
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Delivery note line items
     * @return InvoiceResult
     */
    public function generateDeliveryNote(array $orderData, array $buyerData, array $items): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Delivery note generation requires the szamlaagent SDK.');
        }

        try {
            $deliveryNote = $this->builder->buildDeliveryNote($orderData, $buyerData, $items);
            $result = $agent->generateDeliveryNote($deliveryNote);

            if ($result->isSuccess()) {
                $documentNumber = $result->getDocumentNumber();
                $pdfContent = $result->getPdfFile();

                // Save PDF
                $filename = 'delivery_note_' . $documentNumber . '.pdf';
                $pdfPath = $this->storage->save($filename, $pdfContent);

                $this->log("Delivery note generated: {$documentNumber}", 'INFO');

                return InvoiceResult::success(
                    $documentNumber,
                    $pdfPath,
                    base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Delivery note generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Delivery note generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Delivery note generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a proforma invoice
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Proforma line items
     * @return InvoiceResult
     */
    public function generateProforma(array $orderData, array $buyerData, array $items): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Proforma generation requires the szamlaagent SDK.');
        }

        try {
            $proforma = $this->builder->buildProforma($orderData, $buyerData, $items);
            $result = $agent->generateProforma($proforma);

            if ($result->isSuccess()) {
                $documentNumber = $result->getDocumentNumber();
                $pdfContent = $result->getPdfFile();

                // Save PDF
                $filename = 'proforma_' . $documentNumber . '.pdf';
                $pdfPath = $this->storage->save($filename, $pdfContent);

                $this->log("Proforma generated: {$documentNumber}", 'INFO');

                return InvoiceResult::success(
                    $documentNumber,
                    $pdfPath,
                    base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Proforma generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Proforma generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Proforma generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete a proforma by its number
     *
     * @param string $proformaNumber Proforma document number
     * @return InvoiceResult
     */
    public function deleteProforma(string $proformaNumber): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Proforma deletion requires the szamlaagent SDK.');
        }

        try {
            $result = $agent->getDeleteProforma($proformaNumber);

            if ($result->isSuccess()) {
                $this->log("Proforma deleted: {$proformaNumber}", 'INFO');

                return new InvoiceResult(
                    success: true,
                    invoiceNumber: $proformaNumber
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Proforma deletion failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Proforma deletion failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Proforma deletion failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete proforma(s) by order number
     *
     * @param string $orderNumber Order number
     * @return InvoiceResult
     */
    public function deleteProformaByOrderNumber(string $orderNumber): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Proforma deletion requires the szamlaagent SDK.');
        }

        try {
            $result = $agent->getDeleteProforma(
                $orderNumber,
                \SzamlaAgent\Document\Proforma::FROM_ORDER_NUMBER
            );

            if ($result->isSuccess()) {
                $this->log("Proforma(s) deleted for order: {$orderNumber}", 'INFO');

                return new InvoiceResult(
                    success: true,
                    invoiceNumber: $orderNumber
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Proforma deletion failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Proforma deletion failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Proforma deletion failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a receipt
     *
     * @param array $items Receipt line items
     * @param array $options Receipt options (prefix, payment_method, currency, etc.)
     * @return InvoiceResult
     */
    public function generateReceipt(array $items, array $options = []): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Receipt generation requires the szamlaagent SDK.');
        }

        try {
            $receipt = $this->builder->buildReceipt($items, $options);
            $result = $agent->generateReceipt($receipt);

            if ($result->isSuccess()) {
                $receiptNumber = $result->getDocumentNumber();
                $pdfContent = $result->getPdfFile();

                // Save PDF
                $filename = 'receipt_' . $receiptNumber . '.pdf';
                $pdfPath = $this->storage->save($filename, $pdfContent);

                $this->log("Receipt generated: {$receiptNumber}", 'INFO');

                return InvoiceResult::success(
                    $receiptNumber,
                    $pdfPath,
                    base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Receipt generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Receipt generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Receipt generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get invoice PDF
     *
     * Downloads the PDF of an invoice issued in the account, by invoice number. The SDK's own request XML and
     * PDF file saving is turned off for this call, because the request XML carries the Agent key.
     *
     * @param string $invoiceNumber Invoice number
     * @return InvoiceResult pdfContent holds the PDF base64-encoded
     */
    public function getInvoicePdf(string $invoiceNumber): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Invoice PDF retrieval requires the szamlaagent SDK.');
        }

        try {
            // never write the key-bearing request XML (or a copy of the PDF) to disk
            $agent->setXmlFileSave(false);
            $agent->setPdfFileSave(false);

            $result = $agent->getInvoicePdf($invoiceNumber);

            if ($result->isSuccess()) {
                return new InvoiceResult(
                    success: true,
                    invoiceNumber: $invoiceNumber,
                    pdfContent: base64_encode((string)$result->getPdfFile())
                );
            }

            $code = $result->getErrorCode();
            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Invoice PDF retrieval failed.',
                is_numeric($code) ? (int)$code : null
            );
        } catch (\Throwable $e) {
            $this->log('Invoice PDF retrieval failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Invoice PDF retrieval failed: ' . $e->getMessage());
        }
    }

    /**
     * Get receipt PDF
     *
     * @param string $receiptNumber Receipt number
     * @return InvoiceResult
     */
    public function getReceiptPdf(string $receiptNumber): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Receipt PDF retrieval requires the szamlaagent SDK.');
        }

        try {
            $result = $agent->getReceiptPdf($receiptNumber);

            if ($result->isSuccess()) {
                $pdfContent = $result->getPdfFile();

                return new InvoiceResult(
                    success: true,
                    invoiceNumber: $receiptNumber,
                    pdfContent: base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Receipt PDF retrieval failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Receipt PDF retrieval failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Receipt PDF retrieval failed: ' . $e->getMessage());
        }
    }

    /**
     * Get receipt data
     *
     * @param string $receiptNumber Receipt number
     * @return InvoiceResult Returns result with raw data in pdfContent field (as JSON)
     */
    public function getReceiptData(string $receiptNumber): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Receipt data retrieval requires the szamlaagent SDK.');
        }

        try {
            $result = $agent->getReceiptData($receiptNumber);

            if ($result->isSuccess()) {
                $data = $result->getData();

                return new InvoiceResult(
                    success: true,
                    invoiceNumber: $receiptNumber,
                    pdfContent: json_encode($data, JSON_UNESCAPED_UNICODE)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Receipt data retrieval failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Receipt data retrieval failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Receipt data retrieval failed: ' . $e->getMessage());
        }
    }

    /**
     * Send receipt via email
     *
     * @param string $receiptNumber Receipt number
     * @param string $buyerEmail Buyer email address
     * @param array $sellerConfig Seller email configuration (reply_to, subject, content)
     * @return InvoiceResult
     */
    public function sendReceipt(string $receiptNumber, string $buyerEmail, array $sellerConfig = []): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Receipt sending requires the szamlaagent SDK.');
        }

        try {
            $receipt = new \SzamlaAgent\Document\Receipt\Receipt($receiptNumber);

            // Set buyer
            $buyer = new \SzamlaAgent\Buyer();
            $buyer->setEmail($buyerEmail);
            $receipt->setBuyer($buyer);

            // Set seller with email config
            $seller = new \SzamlaAgent\Seller();
            if (!empty($sellerConfig['reply_to'])) {
                $seller->setEmailReplyTo($sellerConfig['reply_to']);
            }
            if (!empty($sellerConfig['subject'])) {
                $seller->setEmailSubject($sellerConfig['subject']);
            }
            if (!empty($sellerConfig['content'])) {
                $seller->setEmailContent($sellerConfig['content']);
            }
            $receipt->setSeller($seller);

            $result = $agent->sendReceipt($receipt);

            if ($result->isSuccess()) {
                $this->log("Receipt sent: {$receiptNumber} to {$buyerEmail}", 'INFO');

                return new InvoiceResult(
                    success: true,
                    invoiceNumber: $receiptNumber
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Receipt sending failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Receipt sending failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Receipt sending failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a reverse (storno) receipt
     *
     * @param string $receiptNumber Original receipt number to reverse
     * @return InvoiceResult
     */
    public function createReverseReceipt(string $receiptNumber): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Reverse receipt requires the szamlaagent SDK.');
        }

        try {
            $reverseReceipt = $this->builder->buildReverseReceipt($receiptNumber);
            $result = $agent->generateReverseReceipt($reverseReceipt);

            if ($result->isSuccess()) {
                $reverseNumber = $result->getDocumentNumber();
                $pdfContent = $result->getPdfFile();

                // Save PDF
                $filename = 'reverse_receipt_' . $reverseNumber . '.pdf';
                $pdfPath = $this->storage->save($filename, $pdfContent);

                $this->log("Reverse receipt generated: {$reverseNumber} for original: {$receiptNumber}", 'INFO');

                return InvoiceResult::success(
                    $reverseNumber,
                    $pdfPath,
                    base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Reverse receipt generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Reverse receipt generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Reverse receipt generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate invoice using official SDK
     */
    private function generateInvoiceWithAgent(
        \SzamlaAgent\SzamlaAgentAPI $agent,
        array $orderData,
        array $buyerData,
        array $items
    ): InvoiceResult {
        try {
            $invoice = $this->builder->build($orderData, $buyerData, $items);
            $result = $agent->generateInvoice($invoice);

            if ($result->isSuccess()) {
                $invoiceNumber = $result->getDocumentNumber();
                $pdfContent = $result->getPdfFile();

                // Save PDF
                $filename = 'invoice_' . $invoiceNumber . '.pdf';
                $pdfPath = $this->storage->save($filename, $pdfContent);

                $this->log("Invoice generated: {$invoiceNumber}", 'INFO');

                return InvoiceResult::success(
                    $invoiceNumber,
                    $pdfPath,
                    base64_encode($pdfContent)
                );
            }

            return InvoiceResult::error(
                $result->getErrorMessage() ?? 'Invoice generation failed.',
                $result->getErrorCode()
            );
        } catch (\Exception $e) {
            $this->log('Invoice generation failed: ' . $e->getMessage(), 'ERROR');
            return InvoiceResult::error('Invoice generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate invoice using cURL (fallback)
     */
    private function generateInvoiceWithCurl(array $orderData, array $buyerData, array $items): InvoiceResult
    {
        // Build XML request
        $xml = $this->buildInvoiceXml($orderData, $buyerData, $items);

        try {
            $ch = curl_init(self::API_URL);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/xml',
                    'szlahu_key: ' . $this->config['api_key'],
                ],
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_close($ch);

            if ($curlError) {
                return InvoiceResult::error('cURL error: ' . $curlError);
            }

            if ($httpCode !== 200) {
                return InvoiceResult::error('HTTP error: ' . $httpCode);
            }

            // Parse response
            return $this->parseInvoiceResponse($response);
        } catch (\Exception $e) {
            return InvoiceResult::error('Invoice generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Build XML for invoice request
     *
     * @param array $orderData Order information; 'e_invoice' (bool, default false) selects
     *                         electronic vs paper invoice type — kept consistent with the
     *                         SDK code path in InvoiceBuilder::build()
     */
    private function buildInvoiceXml(array $orderData, array $buyerData, array $items): string
    {
        $vatRate = $this->config['vat_rate'] ?? 27;
        $issueDate = date('Y-m-d');
        $fulfillmentDate = $orderData['fulfillment_date'] ?? $issueDate;
        $paymentKey = $this->resolvePaymentMethodKey($orderData['payment_method'] ?? 'bank_transfer');
        $paymentLabel = $orderData['payment_method_label'] ?? $this->getPaymentMethodLabel($paymentKey);
        $eInvoice = !empty($orderData['e_invoice']) ? 'true' : 'false';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $xml .= '<beallitasok>';
        $xml .= '<szamlaagentkulcs>' . htmlspecialchars($this->config['api_key']) . '</szamlaagentkulcs>';
        $xml .= '<eszamla>' . $eInvoice . '</eszamla>';
        $xml .= '<szamlaLetoltes>true</szamlaLetoltes>';
        $xml .= '</beallitasok>';
        $xml .= '<fejlec>';
        $xml .= '<keltDatum>' . $issueDate . '</keltDatum>';
        $xml .= '<teljesitesDatum>' . $fulfillmentDate . '</teljesitesDatum>';
        if ($paymentKey === 'cash') {
            $xml .= '<fizetesiHataridoDatum>' . $issueDate . '</fizetesiHataridoDatum>';
        } else {
            $deadlineDays = $orderData['payment_deadline_days'] ?? 8;
            $xml .= '<fizetesiHataridoDatum>' . date('Y-m-d', strtotime($issueDate . ' +' . $deadlineDays . ' days')) . '</fizetesiHataridoDatum>';
        }
        $xml .= '<fizmod>' . htmlspecialchars($paymentLabel) . '</fizmod>';
        if (!empty($orderData['paid'])) {
            $xml .= '<fizetve>true</fizetve>';
        }
        $currency = $this->mapCurrencyString($orderData['currency'] ?? 'Ft');
        $xml .= '<ppiid>' . htmlspecialchars($currency) . '</ppiid>';
        $language = $orderData['language'] ?? ($this->config['default_language'] ?? 'hu');
        $xml .= '<szamlaNyelve>' . ($language === 'en' ? 'en' : 'hu') . '</szamlaNyelve>';
        if (!empty($orderData['order_number'])) {
            $xml .= '<rendelesSzam>' . htmlspecialchars($orderData['order_number']) . '</rendelesSzam>';
        }
        $xml .= '</fejlec>';
        $xml .= '<vevo>';
        $xml .= '<nev>' . htmlspecialchars($buyerData['name'] ?? '') . '</nev>';
        $xml .= '<irsz>' . htmlspecialchars($buyerData['zip'] ?? '') . '</irsz>';
        $xml .= '<telepules>' . htmlspecialchars($buyerData['city'] ?? '') . '</telepules>';
        $xml .= '<cim>' . htmlspecialchars($buyerData['address'] ?? '') . '</cim>';
        $xml .= '<email>' . htmlspecialchars($buyerData['email'] ?? '') . '</email>';
        $xml .= '<emailKuldes>' . (($buyerData['send_email'] ?? true) ? 'true' : 'false') . '</emailKuldes>';
        if (!empty($buyerData['vat_number'])) {
            $xml .= '<adoszam>' . htmlspecialchars($buyerData['vat_number']) . '</adoszam>';
        }
        $xml .= '</vevo>';
        $xml .= '<tetelek>';

        foreach ($items as $item) {
            $grossPrice = (float) ($item['unit_price_gross'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 1);
            $netPrice = round($grossPrice / (1 + $vatRate / 100), 2);

            $xml .= '<tetel>';
            $xml .= '<megnevezes>' . htmlspecialchars($item['name'] ?? 'Item') . '</megnevezes>';
            $xml .= '<mennyiseg>' . $quantity . '</mennyiseg>';
            $xml .= '<mennyisegiEgyseg>' . htmlspecialchars($item['unit'] ?? 'db') . '</mennyisegiEgyseg>';
            $xml .= '<nettoEgysegar>' . $netPrice . '</nettoEgysegar>';
            $xml .= '<afakulcs>' . ($item['vat_rate'] ?? $vatRate) . '</afakulcs>';
            if (!empty($item['comment'])) {
                $xml .= '<megjegyzes>' . htmlspecialchars($item['comment']) . '</megjegyzes>';
            }
            $xml .= '</tetel>';
        }

        $xml .= '</tetelek>';
        $xml .= '</xmlszamla>';

        return $xml;
    }

    /**
     * Parse invoice response from cURL
     */
    private function parseInvoiceResponse(string $response): InvoiceResult
    {
        // Check for error
        if (preg_match('/<hibakod>(\d+)<\/hibakod>/', $response, $matches)) {
            $errorCode = (int) $matches[1];
            $errorMessage = 'Unknown error';

            if (preg_match('/<hibauzenet>(.*?)<\/hibauzenet>/s', $response, $msgMatches)) {
                $errorMessage = $msgMatches[1];
            }

            return InvoiceResult::error($errorMessage, $errorCode);
        }

        // Extract invoice number
        $invoiceNumber = '';
        if (preg_match('/<szamlaszam>(.*?)<\/szamlaszam>/', $response, $matches)) {
            $invoiceNumber = $matches[1];
        }

        // Extract PDF (base64 encoded in response)
        $pdfContent = '';
        if (preg_match('/<pdf>(.*?)<\/pdf>/s', $response, $matches)) {
            $pdfContent = base64_decode($matches[1]);
        }

        if (empty($invoiceNumber)) {
            return InvoiceResult::error('Could not extract invoice number from response.');
        }

        // Save PDF
        $filename = 'invoice_' . $invoiceNumber . '.pdf';
        $pdfPath = $this->storage->save($filename, $pdfContent);

        $this->log("Invoice generated via cURL: {$invoiceNumber}", 'INFO');

        return InvoiceResult::success(
            $invoiceNumber,
            $pdfPath,
            base64_encode($pdfContent)
        );
    }

    /**
     * Extract error code from XML response
     */
    private function extractErrorCode(string $response): ?int
    {
        if (preg_match('/<hibakod>(\d+)<\/hibakod>/', $response, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /** @var array<string, string> Known payment method keys and their default Hungarian labels */
    private const PAYMENT_METHOD_LABELS = [
        'bank_transfer'    => 'Átutalás',
        'cash'             => 'Készpénz',
        'card'             => 'Bankkártya',
        'cash_on_delivery' => 'Utánvét',
        'paypal'           => 'PayPal',
        'szep_card'        => 'SZÉP kártya',
        'otp_simple'       => 'OTP Simple',
        'cheque'           => 'csekk',
    ];

    /**
     * Resolve and validate payment method key
     *
     * Accepts a known payment method key (e.g. 'bank_transfer', 'cash') and returns it validated.
     * Also supports legacy custom payment_methods config mapping for backward compatibility.
     *
     * @param string $method Payment method key
     * @return string Validated payment method key
     * @throws \InvalidArgumentException If the payment method key is unknown
     */
    private function resolvePaymentMethodKey(string $method): string
    {
        // Known key — return as-is
        if (isset(self::PAYMENT_METHOD_LABELS[$method])) {
            return $method;
        }

        // Legacy: check deprecated payment_methods config
        $customMethods = $this->config['payment_methods'] ?? [];
        if (!empty($customMethods) && isset($customMethods[$method])) {
            $this->log(
                'The "payment_methods" config option is deprecated. '
                . 'Use "payment_method_label" in $orderData instead.',
                'WARNING'
            );
            return $method;
        }

        throw new \InvalidArgumentException(
            "Unknown payment method key: '{$method}'. Valid keys: " . implode(', ', array_keys(self::PAYMENT_METHOD_LABELS))
        );
    }

    /**
     * Get the display label for a payment method key
     *
     * Returns the Hungarian label used in the Szamlazz.hu <fizmod> field.
     * Checks the deprecated payment_methods config first for backward compatibility,
     * then falls back to the built-in default label.
     *
     * @param string $method Validated payment method key
     * @return string Hungarian payment method label
     */
    private function getPaymentMethodLabel(string $method): string
    {
        // Legacy: check deprecated payment_methods config
        $customMethods = $this->config['payment_methods'] ?? [];
        if (!empty($customMethods) && isset($customMethods[$method])) {
            return $customMethods[$method];
        }

        return self::PAYMENT_METHOD_LABELS[$method] ?? self::PAYMENT_METHOD_LABELS['bank_transfer'];
    }

    /**
     * Map currency string for legacy XML path
     *
     * Normalizes currency input (e.g. 'HUF' -> 'Ft') to match
     * the szamlazz.hu API expected values.
     *
     * @param string $currency Currency code or symbol
     * @return string Normalized currency string
     */
    private function mapCurrencyString(string $currency): string
    {
        return match (strtoupper($currency)) {
            'FT', 'HUF' => 'Ft',
            'EUR' => 'EUR',
            'USD' => 'USD',
            default => 'Ft',
        };
    }

    /**
     * Log a message
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if (isset($this->config['log_callback']) && is_callable($this->config['log_callback'])) {
            ($this->config['log_callback'])($message, $level);
        }
    }

    /**
     * Get the storage adapter
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Check if SDK is loaded
     */
    public function isSdkLoaded(): bool
    {
        return $this->sdkLoaded;
    }
}
