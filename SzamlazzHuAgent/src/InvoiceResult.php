<?php

declare(strict_types=1);

namespace SzamlazzHuAgent;

/**
 * Invoice operation result object
 */
class InvoiceResult
{
    public bool $success;
    public ?string $invoiceNumber;
    public ?string $pdfPath;
    public ?string $pdfContent;
    public ?string $errorMessage;
    public ?int $errorCode;

    public function __construct(
        bool $success,
        ?string $invoiceNumber = null,
        ?string $pdfPath = null,
        ?string $pdfContent = null,
        ?string $errorMessage = null,
        ?int $errorCode = null
    ) {
        $this->success = $success;
        $this->invoiceNumber = $invoiceNumber;
        $this->pdfPath = $pdfPath;
        $this->pdfContent = $pdfContent;
        $this->errorMessage = $errorMessage;
        $this->errorCode = $errorCode;
    }

    /**
     * Create a success result
     */
    public static function success(string $invoiceNumber, string $pdfPath, ?string $pdfContent = null): self
    {
        return new self(
            success: true,
            invoiceNumber: $invoiceNumber,
            pdfPath: $pdfPath,
            pdfContent: $pdfContent
        );
    }

    /**
     * Create an error result
     */
    public static function error(string $message, ?int $code = null): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            errorCode: $code
        );
    }

    /**
     * Check if operation was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get error message
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get error code
     */
    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * Get document/invoice number
     */
    public function getDocumentNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    /**
     * Get PDF file path
     */
    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    /**
     * Get PDF content (base64 encoded)
     */
    public function getPdfContent(): ?string
    {
        return $this->pdfContent;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'invoice_number' => $this->invoiceNumber,
            'pdf_path' => $this->pdfPath,
            'pdf_content' => $this->pdfContent,
            'error_message' => $this->errorMessage,
            'error_code' => $this->errorCode,
        ];
    }
}
