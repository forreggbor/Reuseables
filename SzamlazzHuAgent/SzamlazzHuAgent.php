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
     *   - payment_methods: array - Custom payment method mapping
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
            return \SzamlaAgent\SzamlaAgentAPI::create(
                $this->config['api_key'],
                true,
                \SzamlaAgent\Log::LOG_LEVEL_WARN,
                \SzamlaAgent\Response\SzamlaAgentResponse::RESULT_AS_TEXT
            );
        } catch (\Exception $e) {
            $this->log('Failed to create SzamlaAgent: ' . $e->getMessage(), 'ERROR');
            return null;
        }
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
     * Create a storno (reverse) invoice
     *
     * @param string $invoiceNumber Original invoice number to reverse
     * @param string|null $reason Reason for cancellation
     * @return InvoiceResult
     */
    public function createStornoInvoice(string $invoiceNumber, ?string $reason = null): InvoiceResult
    {
        $agent = $this->getAgent();

        if ($agent === null) {
            return InvoiceResult::error('Storno requires the szamlaagent SDK.');
        }

        try {
            $reverseInvoice = $this->builder->buildReverseInvoice($invoiceNumber);

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
     */
    private function buildInvoiceXml(array $orderData, array $buyerData, array $items): string
    {
        $vatRate = $this->config['vat_rate'] ?? 27;
        $issueDate = date('Y-m-d');
        $fulfillmentDate = $orderData['fulfillment_date'] ?? $issueDate;
        $paymentMethod = $this->mapPaymentMethod($orderData['payment_method'] ?? 'bank_transfer');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $xml .= '<beallitasok>';
        $xml .= '<szamlaagentkulcs>' . htmlspecialchars($this->config['api_key']) . '</szamlaagentkulcs>';
        $xml .= '<eszamla>true</eszamla>';
        $xml .= '<szamlaLetoltes>true</szamlaLetoltes>';
        $xml .= '</beallitasok>';
        $xml .= '<fejlec>';
        $xml .= '<keltDatum>' . $issueDate . '</keltDatum>';
        $xml .= '<teljesitesDatum>' . $fulfillmentDate . '</teljesitesDatum>';
        $xml .= '<fizetesiHataridoDatum>' . date('Y-m-d', strtotime($fulfillmentDate . ' +8 days')) . '</fizetesiHataridoDatum>';
        $xml .= '<fizmod>' . htmlspecialchars($paymentMethod) . '</fizmod>';
        $xml .= '<ppiid>' . htmlspecialchars($this->config['currency'] ?? 'HUF') . '</ppiid>';
        $xml .= '<szamlaNyelve>' . ($this->config['default_language'] === 'en' ? 'en' : 'hu') . '</szamlaNyelve>';
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

    /**
     * Map payment method
     */
    private function mapPaymentMethod(string $method): string
    {
        $customMethods = $this->config['payment_methods'] ?? [];

        $defaultMethods = [
            'bank_transfer' => 'Átutalás',
            'cash' => 'Készpénz',
            'card' => 'Bankkártya',
            'cash_on_delivery' => 'Utánvét',
            'paypal' => 'PayPal',
            'szep_card' => 'SZÉP kártya',
            'otp_simple' => 'OTP Simple',
            'cheque' => 'csekk',
        ];

        $methods = array_merge($defaultMethods, $customMethods);

        return $methods[$method] ?? $methods['bank_transfer'];
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
