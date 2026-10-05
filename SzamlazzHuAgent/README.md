# SzamlazzHuAgent

Framework-agnostic PHP module for Szamlazz.hu invoice integration.

## Features

- **Invoices**: Generate paper or e-invoices, preview, and create storno (reverse) invoices
- **Delivery Notes**: Generate szállítólevél documents
- **Proforma Invoices**: Generate díjbekérő with deletion support
- **Receipts**: Generate nyugta with PDF retrieval, email sending, and storno
- **Taxpayer query**: Look up a Hungarian tax number (validity, name, address) from NAV through Szamlazz.hu
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

    // Custom payment method mapping (deprecated, use payment_method_label in $orderData)
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
    reason: 'Customer requested cancellation',
    eInvoice: false                              // Should mirror the original invoice's type; default: false
);
```

**Note:** Storno requires the szamlaagent SDK.

#### getInvoicePdf()

Download the PDF of an invoice issued in the account, by invoice number. `pdfContent` holds the PDF base64-encoded.
The SDK's request XML and PDF file saving is turned off for this call (the request XML carries the Agent key).

```php
$result = $agent->getInvoicePdf(invoiceNumber: 'E-INV-2025-00001');
if ($result->success) {
    $pdf = base64_decode($result->pdfContent);
}
```

**Note:** Invoice PDF retrieval requires the szamlaagent SDK.

### Net-based invoices: issueInvoice() and findInvoiceByExternalId()

For callers that already hold exact net amounts (an ERP document with per-line net, VAT and gross, special VAT codes, discounts, foreign currency), `issueInvoice()` sends every value as given and recomputes nothing. The gross-based `generateInvoice()` above is unchanged.

```php
$result = $agent->issueInvoice(
    header: [
        'issue_date' => '2026-09-29', 'fulfillment_date' => '2026-09-25', 'payment_due' => '2026-10-07',
        'payment_method' => 'bank_transfer',            // or 'payment_method_label' => 'Előreutalás'
        'paid' => false,
        'currency' => 'EUR', 'exchange_rate' => '389.460000', 'exchange_bank' => 'MNB',   // rate required unless HUF
        'language' => 'de',                              // hu | en | de
        'order_number' => 'PO-77', 'comment' => 'Ref.: AJ-2026-19',
        'invoice_type' => 'paper',                       // paper | e
        'external_id' => 'PMERP-1-AJ-2026-19',           // szamlaKulsoAzon, required unless preview
        // optional, continued fulfillment (folyamatos teljesítés): the flag with both dates of the settlement period
        // 'continued_fulfillment' => true, 'settlement_from' => '2026-09-01', 'settlement_to' => '2026-09-30',
        // optional, the bank account printed on the invoice (Számlázz.hu otherwise prints the default one of its own settings)
        // 'bank_name' => 'OTP Bank Nyrt.', 'bank_account' => '11111111-22222222',
    ],
    buyer: [
        'name' => 'Beispiel GmbH', 'zip' => '74321', 'city' => 'Musterstadt', 'address' => 'Beispielweg 1',   // required
        'country' => 'Deutschland', 'tax_number' => '', 'tax_number_eu' => 'DE123456789',
        'tax_payer' => \SzamlaAgent\TaxPayer::TAXPAYER_EU_ENTERPRISE,                                       // required
        'email' => '', 'phone' => '',
    ],
    items: [[
        'name' => 'Licenc', 'quantity' => '2.000', 'unit' => 'db', 'net_unit_price' => '100.5000',
        'vat' => 'EUKT',                                  // '27', '5', '0', 'AAM', 'TAM', 'EUKT', 'HO', … (SDK Item::VAT_*)
        'net_amount' => '201.00', 'vat_amount' => '0.00', 'gross_amount' => '201.00', 'comment' => 'Kedvezmény: 10 %',
    ]],
    preview: false,                                        // true: only the preview PDF, nothing is created
);
```

The result tells three outcomes apart:

| Outcome | `success` | `isUncertain()` | Meaning |
|---------|-----------|-----------------|---------|
| Issued | true | false | `invoiceNumber` (or `PREVIEW`) and `pdfContent` (base64) |
| Refused | false | false | Not sent (invalid input), or Számlázz.hu answered with its error code (`errorCode`): nothing was created |
| Uncertain | false | true | Sent, then the answer was lost or unusable: the invoice **may exist** — look it up before sending again |

- Invalid or missing values (currency, VAT code, buyer type, dates, address, rate) throw inside the builder, and the call is refused before anything is sent. Nothing is defaulted silently.
- A preview answered with a real invoice number is an error (logged), never hidden.
- `findInvoiceByExternalId(string $externalId, array $notFoundCodes)`:
  - found → success with the number and PDF;
  - "not found" → an error result, **only** when Számlázz.hu's error code is in `$notFoundCodes`;
  - anything else (network error, another code) → uncertain.
  - The SDK's own `isExistsInvoiceByExternalId()` reads every exception as "not found" and must not decide whether it is safe to send again.
- Both methods write **no files**: no request/response XML (which carries the agent key) and no PDF. The PDF comes back in `pdfContent`.
- Errors are caught as `\Throwable`, and the log callback never receives the key.
- The agent is a per-key singleton inside the SDK. `issueInvoice()` sets the external id on every call (empty for a preview).
- Seller bank account: with `bank_account` (and optionally `bank_name`) in the header, the invoice carries them as the seller block (`bank`, `bankszamlaszam`); without it nothing is sent and Számlázz.hu prints the default account of its own settings. A `bank_name` without an account is refused before anything is sent. The module does not validate the number; the NAV-reported value should be a Hungarian giro number or an IBAN.
- Continued fulfillment: with `continued_fulfillment` set, `settlement_from` and `settlement_to` are required (real dates, from <= to) and go out as the buyer ledger block (`folyamatosTelj`, `elszDatumTol`, `elszDatumIg`) and as the same period on every item (`tetelFokonyv`). Dates without the flag, or a missing, invalid or reversed date, are refused before anything is sent. The module does not check the fulfillment date against the period.

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

#### queryTaxpayer()

Look up a Hungarian taxpayer by tax number. Szamlazz.hu forwards the query to the NAV Online Szamla system, so no NAV technical user is needed — only the Agent API key already configured for invoicing.

```php
$result = $agent->queryTaxpayer('13421739-2-44'); // any format, the first 8 digits (törzsszám) are used

if (!$result['success']) {
    // Szamlazz.hu/NAV could not answer: $result['error_code'] (e.g. 3 = login failed, 57 = malformed number), $result['message']
} elseif (!$result['valid']) {
    // NAV does not know this tax number
} else {
    $t = $result['taxpayer'];
    // $t['name'], $t['short_name'], $t['tax_id'], $t['vat_code'], $t['county_code'], $t['incorporation'], $t['info_date'], $t['addresses'][]
}
```

Result array:

| Key | Meaning |
|-----|---------|
| `success` | NAV/Szamlazz.hu answered (false = transport, login or request error) |
| `valid` | NAV knows the tax number (`taxpayerValidity`) |
| `error_code` | Szamlazz.hu/NAV error code, `null` if none |
| `message` | Message (the SDK's own errors are Hungarian) |
| `taxpayer` | Parsed data, `null` unless `valid` |
| `raw_xml` | The raw NAV answer, success path only (`null` on errors) |

`taxpayer.addresses[]` items: `type` (`HQ` = registered seat, `SITE`, `BRANCH`), `country_code`, `region`, `postal_code`, `city`, `street_name`, `public_place_category`, `number`, `building`, `staircase`, `floor`, `door`, `lot_number` (missing parts are `null`).

Notes and limits:

- The data is what NAV Online Szamla returns: validity, full name (as registered, typically UPPERCASE with the spelled-out company form), short name (`short_name`, only when NAV has one), tax number details (`vat_code`, `county_code`), business type (`incorporation`: `ORGANIZATION`, `SELF_EMPLOYED` or `TAXABLE_PERSON`) and addresses. There is **no** company registry number or contact data, and no search by name. Note: the official Szamlazz.hu documentation sample still shows the older NAV v2.0 answer (no short name, `incorporation` or county code), while the live answer was observed to be NAV v3.0 (2026-10) — treat every field except `name` and `tax_id` as optional.
- NAV does not always return address data and may change the interface at any time — treat every address part as optional.
- The call uses its own non-singleton agent with all file saving disabled (the answer contains personal data of sole proprietors), so it never changes state shared with invoicing. Timeout: 15 s.
- Unknown tax number is **not** an error: `success = true`, `valid = false`.

## Data Structures

### Order Data

```php
$orderData = [
    'order_number' => 'ORD-2025-001',           // Required
    'fulfillment_date' => '2025-01-18',         // Default: today
    'payment_method' => 'bank_transfer',        // See payment methods below
    'payment_method_label' => 'Átutalás 15 napon belül', // Custom label on invoice (optional)
    'payment_deadline_days' => 8,               // Default: 8
    'paid' => true,                             // Explicitly set paid status (optional)
    'currency' => 'Ft',                         // Ft, HUF, EUR, USD
    'language' => 'hu',                         // hu or en
    'comment' => 'Optional invoice comment',
    'e_invoice' => false,                       // Optional, default: false (paper invoice)
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
        'comment' => 'SKU: ABC-123',            // Optional per-line note on invoice
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

The `payment_method` key in `$orderData` determines payment behavior (e.g. auto-paid for cash). The `payment_method_label` is an optional override for the text displayed on the invoice.

| Key                | Default Label (invoice) |
|--------------------|-------------------------|
| `bank_transfer`    | Átutalás                |
| `cash`             | Készpénz                |
| `card`             | Bankkártya              |
| `cash_on_delivery` | Utánvét                 |
| `paypal`           | PayPal                  |
| `szep_card`        | SZÉP kártya             |
| `otp_simple`       | OTP Simple              |
| `cheque`           | csekk                   |

### Custom Payment Method Label

Use `payment_method_label` to display custom text on the invoice while keeping the correct payment behavior:

```php
// Same payment type, different labels on the invoice
$orderData = [
    'payment_method'       => 'bank_transfer',
    'payment_method_label' => 'Átutalás 15 napon belül',
    'payment_deadline_days' => 15,
];

$orderData = [
    'payment_method'       => 'bank_transfer',
    'payment_method_label' => 'Átutalás 8 napon belül',
    'payment_deadline_days' => 8,
];
```

When `payment_method_label` is omitted, the default Hungarian label from the table above is used.

An unknown `payment_method` key throws `\InvalidArgumentException`.

### Invoice Paid Status

By default, the paid status is determined by the payment method key:
- **`cash`**: automatically marked as **paid** (deadline = issue date)
- **All other keys**: marked as **unpaid** (deadline = issue date + `payment_deadline_days`)

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

### Deprecated: Custom Payment Method Mapping Config

> **Deprecated:** The `payment_methods` config option is deprecated. Use `payment_method_label` in `$orderData` instead.

The old approach mapped custom keys to Hungarian strings in the config. This still works but triggers a deprecation warning:

```php
// Deprecated — avoid in new code
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
