<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Http;

use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Posts JSON to an HTTP API over any PSR-18 client and decodes the JSON reply.
 *
 * @internal Shared plumbing for the bundled providers.
 */
final class JsonHttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function post(string $uri, array $payload, array $headers = []): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new ProviderException('Could not encode the request payload: ' . $e->getMessage(), null, $e);
        }

        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw ProviderException::transport($uri, $e);
        }

        $status = $response->getStatusCode();
        $contents = (string) $response->getBody();
        $decoded = json_decode($contents, true);

        if ($status >= 400) {
            throw ProviderException::httpError($status, self::errorDetail($decoded) ?? self::excerpt($contents));
        }

        if (!is_array($decoded)) {
            throw ProviderException::unexpectedResponse('body is not a JSON object: ' . self::excerpt($contents));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Pulls the human-readable message out of the common error shapes:
     * {"error": {"message": "..."}} (OpenAI-style) and {"error": "..."} (Ollama).
     */
    private static function errorDetail(mixed $decoded): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (is_string($error)) {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return null;
    }

    private static function excerpt(string $contents): string
    {
        $contents = trim($contents);

        if ($contents === '') {
            return '(empty body)';
        }

        return strlen($contents) > 200 ? substr($contents, 0, 200) . '...' : $contents;
    }
}
