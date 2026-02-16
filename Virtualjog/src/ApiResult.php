<?php

declare(strict_types=1);

namespace Virtualjog;

/**
 * Immutable result object for Virtualjog API responses
 *
 * Encapsulates the outcome of an API call with success/error state,
 * response data, error message, and HTTP status code.
 *
 * @example
 * $result = $client->authorize();
 * if ($result->success) {
 *     $clientData = $result->data;
 * } else {
 *     echo "Error: " . $result->errorMessage;
 * }
 */
class ApiResult
{
    /** @var bool Whether the API call succeeded */
    public readonly bool $success;

    /** @var array<string, mixed>|null Response data from the API */
    public readonly ?array $data;

    /** @var string|null Error description if the call failed */
    public readonly ?string $errorMessage;

    /** @var int|null HTTP status code from the response */
    public readonly ?int $httpCode;

    /**
     * Create an API result instance
     *
     * @param bool        $success      Whether the API call succeeded
     * @param array<string, mixed>|null $data Response data from the API
     * @param string|null $errorMessage Error description
     * @param int|null    $httpCode     HTTP status code
     */
    public function __construct(
        bool $success,
        ?array $data = null,
        ?string $errorMessage = null,
        ?int $httpCode = null
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->errorMessage = $errorMessage;
        $this->httpCode = $httpCode;
    }

    /**
     * Create a success result
     *
     * @param array<string, mixed> $data Response data
     * @return self
     */
    public static function success(array $data): self
    {
        return new self(success: true, data: $data);
    }

    /**
     * Create an error result
     *
     * @param string   $message  Error message
     * @param int|null $httpCode HTTP status code
     * @return self
     */
    public static function error(string $message, ?int $httpCode = null): self
    {
        return new self(success: false, errorMessage: $message, httpCode: $httpCode);
    }

    /**
     * Check if the operation was successful
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get the response data
     *
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * Get the error message
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get the HTTP status code
     *
     * @return int|null
     */
    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    /**
     * Convert to array representation
     *
     * @return array{success: bool, data: array<string, mixed>|null, errorMessage: string|null, httpCode: int|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'errorMessage' => $this->errorMessage,
            'httpCode' => $this->httpCode,
        ];
    }
}
