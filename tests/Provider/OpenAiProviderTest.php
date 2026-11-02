<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Provider;

use Ahmed29345\LlmToolCalling\CallableTool;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Provider\OpenAiProvider;
use Ahmed29345\LlmToolCalling\Tests\Support\MockHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenAiProviderTest extends TestCase
{
    public function test_builds_a_chat_completions_request(): void
    {
        $http = new MockHttp(MockHttp::json(self::textReply('Hi')));
        $provider = $this->provider($http, apiKey: 'sk-test', options: ['temperature' => 0.1]);

        $provider->chat(
            [
                Message::system('Be brief.'),
                Message::user('How many absences?'),
                Message::assistant(null, [ToolCall::withArguments('call_1', 'attendance_summary', ['class' => '3A'])]),
                Message::tool('call_1', 'attendance_summary', '{"absent":2}'),
            ],
            [
                new CallableTool('attendance_summary', 'Attendance for a class.', [
                    'type' => 'object',
                    'properties' => ['class' => ['type' => 'string']],
                    'required' => ['class'],
                ], static fn (): string => ''),
                new CallableTool('ping', 'No arguments.', [], static fn (): string => ''),
            ],
        );

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.openai.com/v1/chat/completions', (string) $request->getUri());
        self::assertSame('Bearer sk-test', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = $http->lastJson();
        self::assertSame('gpt-test', $body['model']);
        self::assertSame(0.1, $body['temperature']);
        self::assertSame([
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'How many absences?'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'attendance_summary', 'arguments' => '{"class":"3A"}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"absent":2}'],
        ], $body['messages']);

        self::assertSame([
            'type' => 'function',
            'function' => [
                'name' => 'attendance_summary',
                'description' => 'Attendance for a class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['class' => ['type' => 'string']],
                    'required' => ['class'],
                ],
            ],
        ], $body['tools'][0]);

        // An empty schema must still be a JSON object with "properties": {}.
        self::assertStringContainsString(
            '"parameters":{"type":"object","properties":{}}',
            (string) $request->getBody(),
        );
    }

    public function test_omits_tools_and_auth_when_not_given(): void
    {
        $http = new MockHttp(MockHttp::json(self::textReply('Hi')));

        $this->provider($http, apiKey: null)->chat([Message::user('Hi')], []);

        self::assertArrayNotHasKey('tools', $http->lastJson());
        self::assertFalse($http->lastRequest()->hasHeader('Authorization'));
    }

    public function test_base_url_can_point_at_a_compatible_server(): void
    {
        $http = new MockHttp(MockHttp::json(self::textReply('Hi')));

        $this->provider($http, baseUrl: 'http://localhost:8000/v1/')->chat([Message::user('Hi')], []);

        self::assertSame('http://localhost:8000/v1/chat/completions', (string) $http->lastRequest()->getUri());
    }

    public function test_maps_a_text_reply(): void
    {
        $http = new MockHttp(MockHttp::json(self::textReply('Two absences.')));

        $response = $this->provider($http)->chat([Message::user('x')], []);

        self::assertSame('Two absences.', $response->message->content);
        self::assertFalse($response->message->hasToolCalls());
        self::assertSame('stop', $response->finishReason);
        self::assertArrayHasKey('choices', $response->raw);
    }

    public function test_maps_tool_calls_keeping_raw_argument_text(): void
    {
        $http = new MockHttp(MockHttp::json([
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'attendance_summary', 'arguments' => '{"class":"3A"}']],
                        ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'echo', 'arguments' => '{"broken"']],
                    ],
                ],
            ]],
        ]));

        $response = $this->provider($http)->chat([Message::user('x')], []);
        $calls = $response->message->toolCalls;

        self::assertNull($response->message->content);
        self::assertSame('tool_calls', $response->finishReason);
        self::assertCount(2, $calls);
        self::assertSame(['call_a', 'attendance_summary', '{"class":"3A"}'], [$calls[0]->id, $calls[0]->name, $calls[0]->arguments]);
        self::assertSame('{"broken"', $calls[1]->arguments, 'Malformed arguments are left for the agent to reject.');
    }

    public function test_accepts_object_arguments_from_compatible_servers(): void
    {
        $http = new MockHttp(MockHttp::json([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'tool_calls' => [['id' => 'x', 'function' => ['name' => 'echo', 'arguments' => ['n' => 1]]]],
                ],
            ]],
        ]));

        $call = $this->provider($http)->chat([Message::user('x')], [])->message->toolCalls[0];

        self::assertSame('{"n":1}', $call->arguments);
    }

    public function test_http_errors_become_provider_exceptions(): void
    {
        $http = new MockHttp(MockHttp::json(['error' => ['message' => 'Invalid API key', 'type' => 'auth']], 401));

        try {
            $this->provider($http)->chat([Message::user('x')], []);
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('Invalid API key', $e->getMessage());
        }
    }

    public function test_transport_errors_become_provider_exceptions(): void
    {
        $http = new MockHttp(new ConnectException('Connection refused', new Request('POST', 'http://x')));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->provider($http)->chat([Message::user('x')], []);
    }

    public function test_unexpected_payloads_are_rejected(): void
    {
        $http = new MockHttp(new Response(200, [], 'not json'), MockHttp::json(['choices' => []]));
        $provider = $this->provider($http);

        foreach (['not a JSON object', 'missing choices[0].message'] as $expected) {
            try {
                $provider->chat([Message::user('x')], []);
                self::fail('Expected a ProviderException.');
            } catch (ProviderException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function provider(
        MockHttp $http,
        ?string $apiKey = 'sk-test',
        string $baseUrl = OpenAiProvider::DEFAULT_BASE_URL,
        array $options = [],
    ): OpenAiProvider {
        return new OpenAiProvider($http->client, $http->factory, $http->factory, 'gpt-test', $apiKey, $baseUrl, $options);
    }

    /**
     * @return array<string, mixed>
     */
    private static function textReply(string $content): array
    {
        return [
            'id' => 'chatcmpl-1',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
        ];
    }
}
