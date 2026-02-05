<?php

declare(strict_types=1);

namespace SzamlazzHuAgent;

/**
 * Builds SzamlaAgent Invoice objects from standardized data arrays
 */
class InvoiceBuilder
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Build an Invoice object from order data
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Invoice line items
     * @param bool $preview Whether this is a preview (no invoice created)
     * @return \SzamlaAgent\Document\Invoice\Invoice
     */
    public function build(array $orderData, array $buyerData, array $items, bool $preview = false): \SzamlaAgent\Document\Invoice\Invoice
    {
        // Create e-invoice
        $invoice = new \SzamlaAgent\Document\Invoice\Invoice(
            \SzamlaAgent\Document\Invoice\Invoice::INVOICE_TYPE_E_INVOICE
        );

        // Set header
        $this->setHeader($invoice, $orderData, $preview);

        // Set seller
        $this->setSeller($invoice);

        // Set buyer
        $this->setBuyer($invoice, $buyerData);

        // Add items
        foreach ($items as $item) {
            $this->addItem($invoice, $item);
        }

        return $invoice;
    }

    /**
     * Build a ReverseInvoice (storno) object
     *
     * @param string $originalInvoiceNumber Original invoice number to reverse
     * @return \SzamlaAgent\Document\Invoice\ReverseInvoice
     */
    public function buildReverseInvoice(string $originalInvoiceNumber): \SzamlaAgent\Document\Invoice\ReverseInvoice
    {
        $reverseInvoice = new \SzamlaAgent\Document\Invoice\ReverseInvoice(
            \SzamlaAgent\Document\Invoice\Invoice::INVOICE_TYPE_E_INVOICE
        );

        $header = $reverseInvoice->getHeader();
        $header->setInvoiceNumber($originalInvoiceNumber);

        // Set seller for reverse invoice
        if (!empty($this->config['seller'])) {
            $seller = $this->config['seller'];
            $szamlaSeller = new \SzamlaAgent\Seller(
                $seller['bank_name'] ?? '',
                $seller['bank_account'] ?? ''
            );
            $reverseInvoice->setSeller($szamlaSeller);
        }

        return $reverseInvoice;
    }

    /**
     * Set invoice header
     */
    private function setHeader(\SzamlaAgent\Document\Invoice\Invoice $invoice, array $orderData, bool $preview): void
    {
        $header = $invoice->getHeader();

        $issueDate = date('Y-m-d');
        $fulfillmentDate = $orderData['fulfillment_date'] ?? $issueDate;

        $header->setIssueDate($issueDate);
        $header->setFulfillment($fulfillmentDate);

        if ($preview) {
            $header->setPreviewPdf(true);
        }

        // Payment method
        $paymentMethod = $this->mapPaymentMethod($orderData['payment_method'] ?? 'bank_transfer');
        $header->setPaymentMethod($paymentMethod);

        // Payment deadline
        if ($paymentMethod === 'Készpénz') {
            $header->setPaymentDue($issueDate);
        } else {
            $deadlineDays = $orderData['payment_deadline_days'] ?? 8;
            $header->setPaymentDue(date('Y-m-d', strtotime($fulfillmentDate . ' +' . $deadlineDays . ' days')));
        }

        // Currency
        $currency = $orderData['currency'] ?? 'HUF';
        $header->setCurrency($this->mapCurrency($currency));

        // Language
        $language = $orderData['language'] ?? ($this->config['default_language'] ?? 'hu');
        $header->setLanguage($language === 'en' ?
            \SzamlaAgent\Language::LANGUAGE_EN :
            \SzamlaAgent\Language::LANGUAGE_HU
        );

        // Order number
        if (!empty($orderData['order_number'])) {
            $header->setOrderNumber($orderData['order_number']);
        }

        // Comment
        if (!empty($orderData['comment'])) {
            $header->setComment($orderData['comment']);
        }

        // Invoice prefix
        if (!empty($this->config['invoice_prefix'])) {
            $header->setInvoiceNumberPrefix($this->config['invoice_prefix']);
        }
    }

    /**
     * Set seller information
     */
    private function setSeller(\SzamlaAgent\Document\Invoice\Invoice $invoice): void
    {
        if (empty($this->config['seller'])) {
            return;
        }

        $seller = $this->config['seller'];
        $szamlaSeller = new \SzamlaAgent\Seller(
            $seller['bank_name'] ?? '',
            $seller['bank_account'] ?? ''
        );

        $invoice->setSeller($szamlaSeller);
    }

    /**
     * Set buyer information
     */
    private function setBuyer(\SzamlaAgent\Document\Invoice\Invoice $invoice, array $buyerData): void
    {
        $buyer = new \SzamlaAgent\Buyer(
            $buyerData['name'] ?? '',
            $buyerData['zip'] ?? '',
            $buyerData['city'] ?? '',
            $buyerData['address'] ?? ''
        );

        if (!empty($buyerData['email'])) {
            $buyer->setEmail($buyerData['email']);
        }

        if (!empty($buyerData['vat_number'])) {
            $buyer->setTaxNumber($buyerData['vat_number']);
        }

        if (!empty($buyerData['phone'])) {
            $buyer->setPhone($buyerData['phone']);
        }

        if (array_key_exists('send_email', $buyerData)) {
            $buyer->setSendEmail((bool) $buyerData['send_email']);
        }

        $invoice->setBuyer($buyer);
    }

    /**
     * Add an item to the invoice
     */
    private function addItem(\SzamlaAgent\Document\Invoice\Invoice $invoice, array $item): void
    {
        $name = $item['name'] ?? 'Item';
        $quantity = (float) ($item['quantity'] ?? 1);
        $unit = $item['unit'] ?? 'db';
        $grossUnitPrice = (float) ($item['unit_price_gross'] ?? 0);
        $vatRate = (int) ($item['vat_rate'] ?? $this->config['vat_rate'] ?? 27);

        // Calculate net price from gross
        $netUnitPrice = $this->calculateNetFromGross($grossUnitPrice, $vatRate);
        $grossTotal = $grossUnitPrice * $quantity;
        $netTotal = $this->calculateNetFromGross($grossTotal, $vatRate);
        $vatAmount = $grossTotal - $netTotal;

        $invoiceItem = new \SzamlaAgent\Item\InvoiceItem(
            $name,
            $netUnitPrice,
            $quantity,
            $unit,
            (string) $vatRate
        );

        $invoiceItem->setNetPrice($netTotal);
        $invoiceItem->setVatAmount($vatAmount);
        $invoiceItem->setGrossAmount($grossTotal);

        $invoice->addItem($invoiceItem);
    }

    /**
     * Calculate net price from gross price
     */
    private function calculateNetFromGross(float $gross, int $vatRate): float
    {
        return round($gross / (1 + $vatRate / 100), 2);
    }

    /**
     * Map payment method to Hungarian Szamlazz.hu format
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
     * Map currency code to SzamlaAgent constant
     */
    private function mapCurrency(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => \SzamlaAgent\Currency::CURRENCY_EUR,
            'USD' => \SzamlaAgent\Currency::CURRENCY_USD,
            default => \SzamlaAgent\Currency::CURRENCY_HUF,
        };
    }

    /**
     * Build a DeliveryNote object from order data
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Delivery note line items
     * @return \SzamlaAgent\Document\DeliveryNote
     */
    public function buildDeliveryNote(array $orderData, array $buyerData, array $items): \SzamlaAgent\Document\DeliveryNote
    {
        $deliveryNote = new \SzamlaAgent\Document\DeliveryNote();

        // Set header
        $this->setDeliveryNoteHeader($deliveryNote, $orderData);

        // Set seller
        $this->setDeliveryNoteSeller($deliveryNote);

        // Set buyer
        $this->setDeliveryNoteBuyer($deliveryNote, $buyerData);

        // Add items
        foreach ($items as $item) {
            $this->addDeliveryNoteItem($deliveryNote, $item);
        }

        return $deliveryNote;
    }

    /**
     * Set delivery note header
     */
    private function setDeliveryNoteHeader(\SzamlaAgent\Document\DeliveryNote $deliveryNote, array $orderData): void
    {
        $header = $deliveryNote->getHeader();

        $issueDate = date('Y-m-d');
        $fulfillmentDate = $orderData['fulfillment_date'] ?? $issueDate;

        $header->setIssueDate($issueDate);
        $header->setFulfillment($fulfillmentDate);

        // Payment method
        $paymentMethod = $this->mapPaymentMethod($orderData['payment_method'] ?? 'bank_transfer');
        $header->setPaymentMethod($paymentMethod);

        // Currency
        $currency = $orderData['currency'] ?? 'HUF';
        $header->setCurrency($this->mapCurrency($currency));

        // Language
        $language = $orderData['language'] ?? ($this->config['default_language'] ?? 'hu');
        $header->setLanguage($language === 'en' ?
            \SzamlaAgent\Language::LANGUAGE_EN :
            \SzamlaAgent\Language::LANGUAGE_HU
        );

        // Order number
        if (!empty($orderData['order_number'])) {
            $header->setOrderNumber($orderData['order_number']);
        }

        // Comment
        if (!empty($orderData['comment'])) {
            $header->setComment($orderData['comment']);
        }

        // Invoice prefix
        if (!empty($this->config['invoice_prefix'])) {
            $header->setInvoiceNumberPrefix($this->config['invoice_prefix']);
        }
    }

    /**
     * Set delivery note seller information
     */
    private function setDeliveryNoteSeller(\SzamlaAgent\Document\DeliveryNote $deliveryNote): void
    {
        if (empty($this->config['seller'])) {
            return;
        }

        $seller = $this->config['seller'];
        $szamlaSeller = new \SzamlaAgent\Seller(
            $seller['bank_name'] ?? '',
            $seller['bank_account'] ?? ''
        );

        $deliveryNote->setSeller($szamlaSeller);
    }

    /**
     * Set delivery note buyer information
     */
    private function setDeliveryNoteBuyer(\SzamlaAgent\Document\DeliveryNote $deliveryNote, array $buyerData): void
    {
        $buyer = new \SzamlaAgent\Buyer(
            $buyerData['name'] ?? '',
            $buyerData['zip'] ?? '',
            $buyerData['city'] ?? '',
            $buyerData['address'] ?? ''
        );

        if (!empty($buyerData['email'])) {
            $buyer->setEmail($buyerData['email']);
        }

        if (!empty($buyerData['vat_number'])) {
            $buyer->setTaxNumber($buyerData['vat_number']);
        }

        if (!empty($buyerData['phone'])) {
            $buyer->setPhone($buyerData['phone']);
        }

        if (array_key_exists('send_email', $buyerData)) {
            $buyer->setSendEmail((bool) $buyerData['send_email']);
        }

        $deliveryNote->setBuyer($buyer);
    }

    /**
     * Add an item to the delivery note
     */
    private function addDeliveryNoteItem(\SzamlaAgent\Document\DeliveryNote $deliveryNote, array $item): void
    {
        $name = $item['name'] ?? 'Item';
        $quantity = (float) ($item['quantity'] ?? 1);
        $unit = $item['unit'] ?? 'db';
        $grossUnitPrice = (float) ($item['unit_price_gross'] ?? 0);
        $vatRate = (int) ($item['vat_rate'] ?? $this->config['vat_rate'] ?? 27);

        // Calculate net price from gross
        $netUnitPrice = $this->calculateNetFromGross($grossUnitPrice, $vatRate);
        $grossTotal = $grossUnitPrice * $quantity;
        $netTotal = $this->calculateNetFromGross($grossTotal, $vatRate);
        $vatAmount = $grossTotal - $netTotal;

        $deliveryNoteItem = new \SzamlaAgent\Item\DeliveryNoteItem(
            $name,
            $netUnitPrice,
            $quantity,
            $unit,
            (string) $vatRate
        );

        $deliveryNoteItem->setNetPrice($netTotal);
        $deliveryNoteItem->setVatAmount($vatAmount);
        $deliveryNoteItem->setGrossAmount($grossTotal);

        $deliveryNote->addItem($deliveryNoteItem);
    }

    /**
     * Build a Proforma object from order data
     *
     * @param array $orderData Order information
     * @param array $buyerData Buyer/customer information
     * @param array $items Proforma line items
     * @return \SzamlaAgent\Document\Proforma
     */
    public function buildProforma(array $orderData, array $buyerData, array $items): \SzamlaAgent\Document\Proforma
    {
        $proforma = new \SzamlaAgent\Document\Proforma();

        // Set header
        $this->setProformaHeader($proforma, $orderData);

        // Set seller
        $this->setProformaSeller($proforma);

        // Set buyer
        $this->setProformaBuyer($proforma, $buyerData);

        // Add items
        foreach ($items as $item) {
            $this->addProformaItem($proforma, $item);
        }

        return $proforma;
    }

    /**
     * Set proforma header
     */
    private function setProformaHeader(\SzamlaAgent\Document\Proforma $proforma, array $orderData): void
    {
        $header = $proforma->getHeader();

        $issueDate = date('Y-m-d');
        $fulfillmentDate = $orderData['fulfillment_date'] ?? $issueDate;

        $header->setIssueDate($issueDate);
        $header->setFulfillment($fulfillmentDate);

        // Payment method
        $paymentMethod = $this->mapPaymentMethod($orderData['payment_method'] ?? 'bank_transfer');
        $header->setPaymentMethod($paymentMethod);

        // Payment deadline
        $deadlineDays = $orderData['payment_deadline_days'] ?? 8;
        $header->setPaymentDue(date('Y-m-d', strtotime($fulfillmentDate . ' +' . $deadlineDays . ' days')));

        // Currency
        $currency = $orderData['currency'] ?? 'HUF';
        $header->setCurrency($this->mapCurrency($currency));

        // Language
        $language = $orderData['language'] ?? ($this->config['default_language'] ?? 'hu');
        $header->setLanguage($language === 'en' ?
            \SzamlaAgent\Language::LANGUAGE_EN :
            \SzamlaAgent\Language::LANGUAGE_HU
        );

        // Order number (important for proforma)
        if (!empty($orderData['order_number'])) {
            $header->setOrderNumber($orderData['order_number']);
        }

        // Comment
        if (!empty($orderData['comment'])) {
            $header->setComment($orderData['comment']);
        }

        // Invoice prefix
        if (!empty($this->config['invoice_prefix'])) {
            $header->setInvoiceNumberPrefix($this->config['invoice_prefix']);
        }
    }

    /**
     * Set proforma seller information
     */
    private function setProformaSeller(\SzamlaAgent\Document\Proforma $proforma): void
    {
        if (empty($this->config['seller'])) {
            return;
        }

        $seller = $this->config['seller'];
        $szamlaSeller = new \SzamlaAgent\Seller(
            $seller['bank_name'] ?? '',
            $seller['bank_account'] ?? ''
        );

        $proforma->setSeller($szamlaSeller);
    }

    /**
     * Set proforma buyer information
     */
    private function setProformaBuyer(\SzamlaAgent\Document\Proforma $proforma, array $buyerData): void
    {
        $buyer = new \SzamlaAgent\Buyer(
            $buyerData['name'] ?? '',
            $buyerData['zip'] ?? '',
            $buyerData['city'] ?? '',
            $buyerData['address'] ?? ''
        );

        if (!empty($buyerData['email'])) {
            $buyer->setEmail($buyerData['email']);
        }

        if (!empty($buyerData['vat_number'])) {
            $buyer->setTaxNumber($buyerData['vat_number']);
        }

        if (!empty($buyerData['phone'])) {
            $buyer->setPhone($buyerData['phone']);
        }

        if (array_key_exists('send_email', $buyerData)) {
            $buyer->setSendEmail((bool) $buyerData['send_email']);
        }

        $proforma->setBuyer($buyer);
    }

    /**
     * Add an item to the proforma
     */
    private function addProformaItem(\SzamlaAgent\Document\Proforma $proforma, array $item): void
    {
        $name = $item['name'] ?? 'Item';
        $quantity = (float) ($item['quantity'] ?? 1);
        $unit = $item['unit'] ?? 'db';
        $grossUnitPrice = (float) ($item['unit_price_gross'] ?? 0);
        $vatRate = (int) ($item['vat_rate'] ?? $this->config['vat_rate'] ?? 27);

        // Calculate net price from gross
        $netUnitPrice = $this->calculateNetFromGross($grossUnitPrice, $vatRate);
        $grossTotal = $grossUnitPrice * $quantity;
        $netTotal = $this->calculateNetFromGross($grossTotal, $vatRate);
        $vatAmount = $grossTotal - $netTotal;

        $proformaItem = new \SzamlaAgent\Item\ProformaItem(
            $name,
            $netUnitPrice,
            $quantity,
            $unit,
            (string) $vatRate
        );

        $proformaItem->setNetPrice($netTotal);
        $proformaItem->setVatAmount($vatAmount);
        $proformaItem->setGrossAmount($grossTotal);

        $proforma->addItem($proformaItem);
    }

    /**
     * Build a Receipt object from items and options
     *
     * @param array $items Receipt line items
     * @param array $options Receipt options (prefix, payment_method, currency, etc.)
     * @return \SzamlaAgent\Document\Receipt\Receipt
     */
    public function buildReceipt(array $items, array $options = []): \SzamlaAgent\Document\Receipt\Receipt
    {
        $receipt = new \SzamlaAgent\Document\Receipt\Receipt();

        // Set header
        $this->setReceiptHeader($receipt, $options);

        // Add items
        foreach ($items as $item) {
            $this->addReceiptItem($receipt, $item);
        }

        return $receipt;
    }

    /**
     * Set receipt header
     */
    private function setReceiptHeader(\SzamlaAgent\Document\Receipt\Receipt $receipt, array $options): void
    {
        $header = $receipt->getHeader();

        // Prefix (required)
        if (!empty($options['prefix'])) {
            $header->setPrefix($options['prefix']);
        }

        // Payment method
        if (!empty($options['payment_method'])) {
            $header->setPaymentMethod($this->mapPaymentMethod($options['payment_method']));
        }

        // Currency
        if (!empty($options['currency'])) {
            $header->setCurrency($options['currency']);

            // Exchange settings for non-HUF
            if (strtoupper($options['currency']) !== 'HUF') {
                if (!empty($options['exchange_bank'])) {
                    $header->setExchangeBank($options['exchange_bank']);
                }
                if (!empty($options['exchange_rate'])) {
                    $header->setExchangeRate((float) $options['exchange_rate']);
                }
            }
        }

        // Comment
        if (!empty($options['comment'])) {
            $header->setComment($options['comment']);
        }

        // Call ID (prevents duplicate creation)
        if (!empty($options['call_id'])) {
            $header->setCallId($options['call_id']);
        }

        // PDF template
        if (!empty($options['pdf_template'])) {
            $header->setPdfTemplate($options['pdf_template']);
        }
    }

    /**
     * Add an item to the receipt
     */
    private function addReceiptItem(\SzamlaAgent\Document\Receipt\Receipt $receipt, array $item): void
    {
        $name = $item['name'] ?? 'Item';
        $quantity = (float) ($item['quantity'] ?? 1);
        $unit = $item['unit'] ?? 'db';
        $vatRate = (string) ($item['vat_rate'] ?? $this->config['vat_rate'] ?? 27);

        // Receipt items use net price
        $netUnitPrice = (float) ($item['unit_price_net'] ?? $item['unit_price_gross'] ?? 0);

        // If gross price provided, calculate net
        if (isset($item['unit_price_gross']) && !isset($item['unit_price_net'])) {
            $netUnitPrice = $this->calculateNetFromGross((float) $item['unit_price_gross'], (int) $vatRate);
        }

        $netTotal = $netUnitPrice * $quantity;
        $vatAmount = round($netTotal * (int) $vatRate / 100, 2);
        $grossTotal = $netTotal + $vatAmount;

        $receiptItem = new \SzamlaAgent\Item\ReceiptItem(
            $name,
            $netUnitPrice,
            $quantity,
            $unit,
            $vatRate
        );

        $receiptItem->setNetPrice($netTotal);
        $receiptItem->setVatAmount($vatAmount);
        $receiptItem->setGrossAmount($grossTotal);

        $receipt->addItem($receiptItem);
    }

    /**
     * Build a ReverseReceipt object
     *
     * @param string $receiptNumber Original receipt number to reverse
     * @return \SzamlaAgent\Document\Receipt\ReverseReceipt
     */
    public function buildReverseReceipt(string $receiptNumber): \SzamlaAgent\Document\Receipt\ReverseReceipt
    {
        return new \SzamlaAgent\Document\Receipt\ReverseReceipt($receiptNumber);
    }
}
