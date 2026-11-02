<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Tests\Unit;

use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ToolCallTest extends TestCase
{
    public function test_decodes_a_json_object(): void
    {
        $call = new ToolCall('c1', 'lookup', '{"class":"Grade 3A","limit":5}');

        self::assertSame(['class' => 'Grade 3A', 'limit' => 5], $call->decodedArguments());
    }

    public function test_empty_arguments_decode_to_an_empty_array(): void
    {
        self::assertSame([], (new ToolCall('c1', 'lookup', ''))->decodedArguments());
        self::assertSame([], (new ToolCall('c1', 'lookup', '{}'))->decodedArguments());
    }

    public function test_malformed_json_throws(): void
    {
        $this->expectException(JsonException::class);

        (new ToolCall('c1', 'lookup', '{"class": '))->decodedArguments();
    }

    public function test_json_that_is_not_an_object_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ToolCall('c1', 'lookup', '["Grade 3A"]'))->decodedArguments();
    }

    public function test_empty_arguments_encode_as_an_object(): void
    {
        self::assertSame('{}', ToolCall::withArguments('c1', 'lookup', [])->arguments);
        self::assertSame('{"name":"Zoë"}', ToolCall::withArguments('c1', 'lookup', ['name' => 'Zoë'])->arguments);
    }

    public function test_provider_arguments_are_normalised_to_json_text(): void
    {
        self::assertSame('{"a":1}', ToolCall::argumentsToJson('{"a":1}'));
        self::assertSame('{"a":1}', ToolCall::argumentsToJson(['a' => 1]));
        self::assertSame('{}', ToolCall::argumentsToJson([]));
        self::assertSame('{}', ToolCall::argumentsToJson(null));
        self::assertSame('not json', ToolCall::argumentsToJson('not json'));
    }
}
