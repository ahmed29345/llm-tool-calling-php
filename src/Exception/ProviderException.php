<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Exception;

use RuntimeException;
use Throwable;

/**
 * The model provider could not be reached or returned something unusable.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transport(string $uri, Throwable $previous): self
    {
        return new self(sprintf('Request to %s failed: %s', $uri, $previous->getMessage()), null, $previous);
    }

    public static function httpError(int $statusCode, string $detail): self
    {
        return new self(sprintf('Provider responded with HTTP %d: %s', $statusCode, $detail), $statusCode);
    }

    public static function unexpectedResponse(string $detail): self
    {
        return new self('Unexpected provider response: ' . $detail);
    }
}
