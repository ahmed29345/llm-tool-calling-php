<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Provider;

use Ahmed29345\LlmToolCalling\CallableTool;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Provider\OllamaProvider;
use Ahmed29345\LlmToolCalling\Tests\Support\MockHttp;
use PHPUnit\Framework\TestCase;

final class OllamaProviderTest extends TestCase
{
    public function test_builds_an_api_chat_request(): void
    {
        $http = new MockHttp(MockHttp::json(self::reply(['role' => 'assistant', 'content' => 'ok'])));
        $provider = new OllamaProvider(
            $http->client,
            $http->factory,
            $http->factory,
            'llama3.1',
            'http://ollama.local:11434/',
            ['temperature' => 0],
        );

        $provider->chat(
            [
                Message::user('Absences in 3A?'),
                Message::assistant('', [
                    ToolCall::withArguments('c1', 'attendance_summary', ['class' => '3A']),
                    new ToolCall('c2', 'ping', ''),
                    new ToolCall('c3', 'ping', '{"broken"'),
                ]),
                Message::tool('c1', 'attendance_summary', '{"absent":2}'),
            ],
            [new CallableTool('attendance_summary', 'Attendance.', ['type' => 'object', 'properties' => ['class' => ['type' => 'string']]], static fn (): string => '')],
        );

        self::assertSame('http://ollama.local:11434/api/chat', (string) $http->lastRequest()->getUri());
        self::assertFalse($http->lastRequest()->hasHeader('Authorization'));

        $body = $http->lastJson();
        self::assertSame('llama3.1', $body['model']);
        self::assertFalse($body['stream']);
        self::assertSame(['temperature' => 0], $body['options']);
        self::assertSame('attendance_summary', $body['tools'][0]['function']['name']);
        self::assertSame([
            ['role' => 'user', 'content' => 'Absences in 3A?'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    ['function' => ['name' => 'attendance_summary', 'arguments' => ['class' => '3A']]],
                    ['function' => ['name' => 'ping', 'arguments' => []]],
                    ['function' => ['name' => 'ping', 'arguments' => []]],
                ],
            ],
            ['role' => 'tool', 'content' => '{"absent":2}', 'tool_name' => 'attendance_summary'],
        ], $body['messages']);

        // Arguments go over the wire as JSON objects, never as strings or arrays.
        self::assertStringContainsString('"arguments":{"class":"3A"}', (string) $http->lastRequest()->getBody());
        self::assertStringContainsString('"name":"ping","arguments":{}', (string) $http->lastRequest()->getBody());
    }

    public function test_omits_tools_and_options_when_empty(): void
    {
        $http = new MockHttp(MockHttp::json(self::reply(['role' => 'assistant', 'content' => 'ok'])));

        $this->provider($http)->chat([Message::user('Hi')], []);

        self::assertArrayNotHasKey('tools', $http->lastJson());
        self::assertArrayNotHasKey('options', $http->lastJson());
        self::assertSame('http://localhost:11434/api/chat', (string) $http->lastRequest()->getUri());
    }

    public function test_maps_a_text_reply(): void
    {
        $http = new MockHttp(MockHttp::json(self::reply(['role' => 'assistant', 'content' => 'Two absences.'])));

        $response = $this->provider($http)->chat([Message::user('x')], []);

        self::assertSame('Two absences.', $response->message->content);
        self::assertSame('stop', $response->finishReason);
    }

    public function test_maps_tool_calls_with_object_arguments_and_generates_missing_ids(): void
    {
        $http = new MockHttp(MockHttp::json(self::reply([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['function' => ['name' => 'attendance_summary', 'arguments' => ['class' => '3A']]],
                ['function' => ['name' => 'ping', 'arguments' => []]],
                ['id' => 'server-id', 'function' => ['name' => 'ping', 'arguments' => '{"raw":true}']],
            ],
        ])));

        $response = $this->provider($http)->chat([Message::user('x')], []);
        $calls = $response->message->toolCalls;

        self::assertNull($response->message->content);
        self::assertCount(3, $calls);
        self::assertSame('{"class":"3A"}', $calls[0]->arguments);
        self::assertSame('{}', $calls[1]->arguments);
        self::assertSame('{"raw":true}', $calls[2]->arguments);
        self::assertSame('server-id', $calls[2]->id);
        self::assertNotSame($calls[0]->id, $calls[1]->id);
        self::assertNotSame('', $calls[0]->id);
    }

    public function test_error_responses_become_provider_exceptions(): void
    {
        $http = new MockHttp(MockHttp::json(['error' => 'model "llama9" not found, try pulling it first'], 404));

        try {
            $this->provider($http)->chat([Message::user('x')], []);
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(404, $e->statusCode);
            self::assertStringContainsString('not found, try pulling it first', $e->getMessage());
        }
    }

    public function test_reply_without_a_message_is_rejected(): void
    {
        $http = new MockHttp(MockHttp::json(['model' => 'llama3.1', 'done' => true]));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('missing "message"');

        $this->provider($http)->chat([Message::user('x')], []);
    }

    private function provider(MockHttp $http): OllamaProvider
    {
        return new OllamaProvider($http->client, $http->factory, $http->factory, 'llama3.1');
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function reply(array $message): array
    {
        return [
            'model' => 'llama3.1',
            'created_at' => '2026-01-01T00:00:00Z',
            'message' => $message,
            'done' => true,
            'done_reason' => 'stop',
        ];
    }
}
