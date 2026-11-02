<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Provider;

use Ahmed29345\LlmToolCalling\Chat\ChatProvider;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\Role;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Http\JsonHttpClient;
use Ahmed29345\LlmToolCalling\Tool;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * OpenAI Chat Completions API (POST {baseUrl}/chat/completions) with "tools" and "tool_calls".
 *
 * Point $baseUrl at any OpenAI-compatible server (vLLM, LM Studio, llama.cpp,
 * Ollama's /v1, a gateway) to use it instead.
 */
final class OpenAiProvider implements ChatProvider
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private readonly JsonHttpClient $http;

    private readonly string $endpoint;

    /**
     * @param array<string, mixed>  $options Extra request fields, e.g. ['temperature' => 0.2, 'tool_choice' => 'auto']
     * @param array<string, string> $headers Extra HTTP headers, e.g. ['OpenAI-Organization' => '...']
     */
    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        private readonly string $model,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly array $options = [],
        private readonly array $headers = [],
    ) {
        $this->http = new JsonHttpClient($client, $requestFactory, $streamFactory);
        $this->endpoint = rtrim($baseUrl, '/') . '/chat/completions';
    }

    public function chat(array $messages, array $tools): ChatResponse
    {
        $payload = [
            ...$this->options,
            'model' => $this->model,
            'messages' => array_map($this->encodeMessage(...), $messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn (Tool $tool): array => FunctionDefinition::fromTool($tool),
                $tools,
            );
        }

        $headers = $this->headers;

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $this->decodeResponse($this->http->post($this->endpoint, $payload, $headers));
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeMessage(Message $message): array
    {
        return match ($message->role) {
            Role::System, Role::User => [
                'role' => $message->role->value,
                'content' => $message->content ?? '',
            ],
            Role::Assistant => $this->encodeAssistantMessage($message),
            Role::Tool => [
                'role' => 'tool',
                'tool_call_id' => $message->toolCallId,
                'content' => $message->content ?? '',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeAssistantMessage(Message $message): array
    {
        $encoded = ['role' => 'assistant', 'content' => $message->content];

        if ($message->hasToolCalls()) {
            $encoded['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => $call->arguments],
                ],
                $message->toolCalls,
            );
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ProviderException
     */
    private function decodeResponse(array $data): ChatResponse
    {
        $choices = $data['choices'] ?? null;
        $choice = is_array($choices) ? ($choices[0] ?? null) : null;

        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            throw ProviderException::unexpectedResponse('missing choices[0].message');
        }

        $message = $choice['message'];
        $toolCalls = [];

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $index => $call) {
            $function = is_array($call) ? ($call['function'] ?? null) : null;

            if (!is_array($function) || !is_string($function['name'] ?? null)) {
                throw ProviderException::unexpectedResponse(sprintf('tool call #%s has no function name', $index));
            }

            $id = is_string($call['id'] ?? null) ? $call['id'] : 'call_' . $index;
            $toolCalls[] = new ToolCall($id, $function['name'], ToolCall::argumentsToJson($function['arguments'] ?? null));
        }

        return new ChatResponse(
            Message::assistant(is_string($message['content'] ?? null) ? $message['content'] : null, $toolCalls),
            is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
            $data,
        );
    }
}
