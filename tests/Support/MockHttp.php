<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A real PSR-18 client (Guzzle) wired to a queue of canned responses, recording every request.
 */
final class MockHttp
{
    public readonly Client $client;

    public readonly HttpFactory $factory;

    /** @var list<RequestInterface> */
    private array $requests = [];

    public function __construct(ResponseInterface|Throwable ...$queue)
    {
        $stack = HandlerStack::create(new MockHandler(array_values($queue)));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->requests[] = $request;

            return $request;
        }));

        $this->client = new Client(['handler' => $stack]);
        $this->factory = new HttpFactory();
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new RuntimeException('No request was sent.')];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
