# SzamlazzHuAgent

Framework-agnostic PHP module for Szamlazz.hu invoice integration.

## Features

- **Invoices**: Generate e-invoices, preview, and create storno (reverse) invoices
- **Delivery Notes**: Generate szállítólevél documents
- **Proforma Invoices**: Generate díjbekérő with deletion support
- **Receipts**: Generate nyugta with PDF retrieval, email sending, and storno
- Uses official szamlaagent SDK (v2.10.23)
- cURL fallback for basic invoice generation
- Configurable storage path for all SDK-generated files
- Configurable payment methods, VAT rates, and seller information
- Framework-agnostic with custom storage adapter support

## Requirements

- PHP 8.3+
- cURL extension
- Szamlazz.hu account with API key

## Installation

1. Copy the `SzamlazzHuAgent` folder to your project
2. The `szamlaagent/` subfolder contains the official SDK (do not modify)

## Quick Start

```php
<?php

require_once 'SzamlazzHuAgent/SzamlazzHuAgent.php';

use SzamlazzHuAgent\SzamlazzHuAgent;

// Initialize
$agent = new SzamlazzHuAgent([
    'api_key' => 'your-szamlazz-api-key',
    'storage_path' => __DIR__ . '/storage/invoices',
    'seller' => [
        'bank_name' => 'OTP Bank',
        'bank_account' => '12345678-12345678-12345678',
    ],
]);

// Validate connection
$validation = $agent->validateConnection();
if (!$validation['success']) {
    die('API connection failed: ' . $validation['message']);
}

// Generate invoice
$result = $agent->generateInvoice(
    orderData: [
        'order_number' => 'ORD-2025-001',
        'fulfillment_date' => date('Y-m-d'),
        'payment_method' => 'bank_transfer',
    ],
    buyerData: [
        'name' => 'Customer Name',
        'zip' => '1234',
        'city' => 'Budapest',
        'address' => 'Customer Street 1',
        'email' => 'customer@example.com',
    ],
    items: [
        [
            'name' => 'Product Name',
            'quantity' => 2,
            'unit' => 'db',
            'unit_price_gross' => 12700,
            'vat_rate' => 27,
        ],
    ]
);

if ($result->success) {
    echo "Invoice generated: " . $result->invoiceNumber . "\n";
    echo "PDF saved to: " . $result->pdfPath . "\n";
} else {
    echo "Error: " . $result->errorMessage . "\n";
}
```

## Configuration

### Full Configuration Example

```php
$agent = new SzamlazzHuAgent([
    // Required
    'api_key' => 'your-szamlazz-api-key',
    'storage_path' => '/path/to/storage/szamlaagent',  // Base path for all SDK files

    // Seller information (recommended)
    'seller' => [
        'bank_name' => 'Bank Name',
        'bank_account' => '12345678-12345678-12345678',
    ],

    // Optional settings
    'vat_rate' => 27,              // Default VAT rate (default: 27)
    'invoice_prefix' => 'INV-',    // Invoice number prefix
    'default_language' => 'hu',    // Default language: 'hu' or 'en'

    // Custom payment method mapping
    'payment_methods' => [
        'stripe' => 'Bankkártya',
        'wire' => 'Átutalás',
    ],

    // Logging callback
    'log_callback' => function(string $message, string $level) {
        error_log("[{$level}] SzamlazzHuAgent: {$message}");
    },
]);
```

## API Reference

### Invoice Methods

#### generateInvoice()

Generate an invoice and save the PDF.

```php
$result = $agent->generateInvoice(
    orderData: [...],
    buyerData: [...],
    items: [...]
);
```

**Returns:** `InvoiceResult` object

### generatePreview()

Generate a preview PDF without creating an actual invoice.

```php
$result = $agent->generatePreview(
    orderData: [...],
    buyerData: [...],
    items: [...]
);

if ($result->success) {
    // $result->pdfContent contains base64-encoded PDF
    header('Content-Type: application/pdf');
    echo base64_decode($result->pdfContent);
}
```

**Note:** Preview requires the szamlaagent SDK.

#### createStornoInvoice()

Create a reverse (storno) invoice for an existing invoice.

```php
$result = $agent->createStornoInvoice(
    invoiceNumber: 'E-INV-2025-00001',
    reason: 'Customer requested cancellation'
);
```

**Note:** Storno requires the szamlaagent SDK.

### Delivery Note Methods

#### generateDeliveryNote()

Generate a delivery note (szállítólevél).

```php
$result = $agent->generateDeliveryNote(
    orderData: [
        'order_number' => 'ORD-2025-001',
        'fulfillment_date' => date('Y-m-d'),
    ],
    buyerData: [
        'name' => 'Customer Name',
        'zip' => '1234',
        'city' => 'Budapest',
        'address' => 'Customer Street 1',
    ],
    items: [
        ['name' => 'Product', 'quantity' => 2, 'unit' => 'db', 'unit_price_gross' => 12700, 'vat_rate' => 27],
    ]
);
```

**Note:** Requires the szamlaagent SDK.

### Proforma Invoice Methods

#### generateProforma()

Generate a proforma invoice (díjbekérő).

```php
$result = $agent->generateProforma(
    orderData: [
        'order_number' => 'ORD-2025-001',
        'fulfillment_date' => date('Y-m-d'),
        'payment_method' => 'bank_transfer',
        'payment_deadline_days' => 8,
    ],
    buyerData: [
        'name' => 'Customer Name',
        'zip' => '1234',
        'city' => 'Budapest',
        'address' => 'Customer Street 1',
        'email' => 'customer@example.com',
    ],
    items: [
        ['name' => 'Product', 'quantity' => 1, 'unit' => 'db', 'unit_price_gross' => 12700, 'vat_rate' => 27],
    ]
);
```

#### deleteProforma()

Delete a proforma by its document number.

```php
$result = $agent->deleteProforma(proformaNumber: 'D-2025-00001');
```

#### deleteProformaByOrderNumber()

Delete all proformas associated with an order number.

```php
$result = $agent->deleteProformaByOrderNumber(orderNumber: 'ORD-2025-001');
```

**Note:** Proforma methods require the szamlaagent SDK.

### Receipt Methods

Receipts (nyugta) are used for POS/cash register transactions.

#### generateReceipt()

Generate a receipt.

```php
$result = $agent->generateReceipt(
    items: [
        ['name' => 'Product 1', 'quantity' => 2, 'unit' => 'db', 'unit_price_gross' => 1270, 'vat_rate' => 27],
        ['name' => 'Product 2', 'quantity' => 1, 'unit' => 'db', 'unit_price_gross' => 2540, 'vat_rate' => 27],
    ],
    options: [
        'prefix' => 'NYGTA',           // Required: receipt number prefix
        'payment_method' => 'cash',    // cash, card, bank_transfer
        'currency' => 'Ft',
        'comment' => 'Optional comment',
    ]
);

if ($result->success) {
    echo "Receipt generated: " . $result->invoiceNumber;
}
```

**Receipt Options:**

| Option | Description |
|--------|-------------|
| `prefix` | Receipt number prefix (required) |
| `payment_method` | Payment method: cash, card, bank_transfer, etc. |
| `currency` | Currency: Ft, HUF, EUR, USD |
| `exchange_bank` | Exchange bank for non-HUF (e.g., 'MNB') |
| `exchange_rate` | Exchange rate (uses MNB rate if not set) |
| `comment` | Optional comment |
| `call_id` | Unique ID to prevent duplicate creation |
| `pdf_template` | Custom PDF template ID |

#### getReceiptPdf()

Get PDF of an existing receipt.

```php
$result = $agent->getReceiptPdf(receiptNumber: 'NYGTA-2025-00001');

if ($result->success) {
    $pdfContent = base64_decode($result->pdfContent);
}
```

#### getReceiptData()

Get receipt data as JSON.

```php
$result = $agent->getReceiptData(receiptNumber: 'NYGTA-2025-00001');

if ($result->success) {
    $data = json_decode($result->pdfContent, true);
}
```

#### sendReceipt()

Send receipt via email.

```php
$result = $agent->sendReceipt(
    receiptNumber: 'NYGTA-2025-00001',
    buyerEmail: 'customer@example.com',
    sellerConfig: [
        'reply_to' => 'shop@example.com',
        'subject' => 'Your receipt',
        'content' => 'Thank you for your purchase!',
    ]
);
```

#### createReverseReceipt()

Create a reverse (storno) receipt.

```php
$result = $agent->createReverseReceipt(receiptNumber: 'NYGTA-2025-00001');

if ($result->success) {
    echo "Reverse receipt: " . $result->invoiceNumber;
}
```

**Note:** All receipt methods require the szamlaagent SDK.

### Utility Methods

#### validateConnection()

Test the API connection.

```php
$result = $agent->validateConnection();

if ($result['success']) {
    echo "Connected successfully";
} else {
    echo "Error: " . $result['message'];
}
```

## Data Structures

### Order Data

```php
$orderData = [
    'order_number' => 'ORD-2025-001',           // Required
    'fulfillment_date' => '2025-01-18',         // Default: today
    'payment_method' => 'bank_transfer',        // See payment methods below
    'payment_deadline_days' => 8,               // Default: 8
    'paid' => true,                             // Explicitly set paid status (optional)
    'currency' => 'Ft',                         // Ft, HUF, EUR, USD
    'language' => 'hu',                         // hu or en
    'comment' => 'Optional invoice comment',
];
```

### Buyer Data

```php
$buyerData = [
    'name' => 'Customer Name or Company',       // Required
    'zip' => '1234',                            // Required
    'city' => 'Budapest',                       // Required
    'address' => 'Street 1',                    // Required
    'email' => 'customer@example.com',          // Recommended
    'vat_number' => 'HU12345678',               // Optional
    'phone' => '+36201234567',                  // Optional
    'send_email' => true,                       // Optional, default: true
];
```

### Invoice Items

```php
$items = [
    [
        'name' => 'Product Name',               // Required
        'quantity' => 2,                        // Default: 1
        'unit' => 'db',                         // Default: 'db'
        'unit_price_gross' => 12700,            // Required (gross price)
        'vat_rate' => 27,                       // Default: config vat_rate
    ],
    // Discounts as negative items
    [
        'name' => 'Discount - 10%',
        'quantity' => 1,
        'unit' => 'db',
        'unit_price_gross' => -1270,            // Negative for discounts
        'vat_rate' => 27,
    ],
];
```

### InvoiceResult Object

```php
class InvoiceResult {
    public bool $success;
    public ?string $invoiceNumber;    // e.g., 'E-INV-2025-00001'
    public ?string $pdfPath;          // Full path to saved PDF
    public ?string $pdfContent;       // Base64-encoded PDF content
    public ?string $errorMessage;
    public ?int $errorCode;
}
```

## Payment Methods

Default mapping from your application's payment methods to Szamlazz.hu:

| Key               | Szamlazz.hu Value |
|-------------------|-------------------|
| `bank_transfer`   | Átutalás          |
| `cash`            | Készpénz          |
| `card`            | Bankkártya        |
| `cash_on_delivery`| Utánvét           |
| `paypal`          | PayPal            |
| `szep_card`       | SZÉP kártya       |
| `otp_simple`      | OTP Simple        |
| `cheque`          | csekk             |

### Invoice Paid Status

By default, Szamlazz.hu determines the paid status based on the payment method and deadline:
- **Cash** (`Készpénz`): automatically marked as **paid** (deadline = issue date)
- **All other methods**: marked as **unpaid** (deadline = fulfillment + `payment_deadline_days`)

You can override this with the `paid` parameter in `$orderData`:

```php
// Explicitly mark a bank transfer invoice as paid
$orderData = [
    'payment_method' => 'bank_transfer',
    'paid'           => true,
];

// Explicitly mark a cash invoice as unpaid
$orderData = [
    'payment_method' => 'cash',
    'paid'           => false,
];
```

When `paid` is omitted, Szamlazz.hu applies its default logic.

Add custom mappings via configuration:

```php
'payment_methods' => [
    'stripe' => 'Bankkártya',
    'bitcoin' => 'Egyéb',
]
```

## Custom Storage Adapter

Implement `StorageInterface` for custom storage (e.g., S3, cloud storage):

```php
use SzamlazzHuAgent\Contracts\StorageInterface;

class S3Storage implements StorageInterface
{
    public function save(string $filename, string $content): string { /* ... */ }
    public function getPath(string $filename): string { /* ... */ }
    public function exists(string $filename): bool { /* ... */ }
    public function get(string $filename): ?string { /* ... */ }
    public function delete(string $filename): bool { /* ... */ }
}

$agent = new SzamlazzHuAgent([
    'api_key' => 'your-key',
    'storage_adapter' => new S3Storage($bucket),
]);
```

## Storage Structure

The `storage_path` configuration sets the base directory for all SDK-generated files:

```
storage_path/
├── pdf/          # Invoice PDFs, storno PDFs, delivery notes, etc.
├── xmls/         # XML request/response files
├── logs/         # Log files
├── cookie/       # Session cookies
└── attachments/  # Invoice attachments
```

Subdirectories are created automatically as needed.

## szamlaagent SDK

The `szamlaagent/` folder contains the official Szamlazz.hu PHP SDK. This folder should not be modified as it may be updated by the Szamlazz.hu development team.

**SDK Features Used:**
- `SzamlaAgentAPI` - Main API client
- `Invoice` / `ReverseInvoice` - Document types
- `InvoiceItem` - Line items
- `Buyer` / `Seller` - Party information
- `Currency` / `Language` - Constants

When the SDK is not available, the module falls back to basic cURL-based invoice generation.

## Error Handling

```php
$result = $agent->generateInvoice($orderData, $buyerData, $items);

if (!$result->success) {
    // Log error
    error_log("Invoice error [{$result->errorCode}]: {$result->errorMessage}");

    // Handle specific error codes
    switch ($result->errorCode) {
        case 3:
        case 49:
        case 50:
        case 51:
            // Authentication error
            break;
        default:
            // Other error
            break;
    }
}
```

## Framework Integration Examples

### Laravel

```php
// config/services.php
'szamlazz' => [
    'api_key' => env('SZAMLAZZ_API_KEY'),
],

// App\Services\InvoiceService.php
use SzamlazzHuAgent\SzamlazzHuAgent;

class InvoiceService
{
    private SzamlazzHuAgent $agent;

    public function __construct()
    {
        $this->agent = new SzamlazzHuAgent([
            'api_key' => config('services.szamlazz.api_key'),
            'storage_path' => storage_path('invoices'),
            'seller' => config('company'),
        ]);
    }

    public function generateForOrder(Order $order): InvoiceResult
    {
        return $this->agent->generateInvoice(
            orderData: $this->mapOrderData($order),
            buyerData: $this->mapBuyerData($order->customer),
            items: $this->mapItems($order->items),
        );
    }
}
```

### Plain PHP

```php
// bootstrap.php
require_once 'vendor/autoload.php';
require_once 'SzamlazzHuAgent/SzamlazzHuAgent.php';

$szamlazz = new SzamlazzHuAgent([
    'api_key' => $_ENV['SZAMLAZZ_API_KEY'],
    'storage_path' => __DIR__ . '/storage/invoices',
]);

// invoice.php
$result = $szamlazz->generateInvoice($orderData, $buyerData, $items);
```

## License

MIT License
