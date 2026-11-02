<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Unit;

use Ahmed29345\LlmToolCalling\CallableTool;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Provider\FakeProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FakeProviderTest extends TestCase
{
    public function test_replays_responses_in_order_and_records_requests(): void
    {
        $tool = new CallableTool('ping', 'Ping.', [], static fn (): string => 'pong');
        $provider = (new FakeProvider(ChatResponse::text('one')))
            ->push(static fn (array $messages, array $tools): ChatResponse => ChatResponse::text(
                sprintf('%d messages, %d tools', count($messages), count($tools)),
            ));

        self::assertSame('one', $provider->chat([Message::user('a')], [])->message->content);
        self::assertSame('2 messages, 1 tools', $provider->chat([Message::user('a'), Message::user('b')], [$tool])->message->content);
        self::assertSame(0, $provider->remaining());
        self::assertSame(['ping'], $provider->toolNamesOffered(1));
        self::assertSame([], $provider->toolNamesOffered(5));
        self::assertCount(2, $provider->requests());
    }

    public function test_throws_when_the_script_runs_out(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('ran out of scripted responses at request #1');

        (new FakeProvider())->chat([Message::user('a')], []);
    }

    public function test_a_chat_response_must_carry_an_assistant_message(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatResponse(Message::user('not a reply'));
    }
}
