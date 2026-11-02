<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Chat;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * A tool call requested by the model.
 *
 * Arguments are kept as the raw JSON text the model produced, because models
 * do produce malformed JSON and the agent needs to report that back rather
 * than fail while parsing the provider response.
 */
final readonly class ToolCall
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        public string $id,
        public string $name,
        public string $arguments = '{}',
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws JsonException
     */
    public static function withArguments(string $id, string $name, array $arguments): self
    {
        return new self($id, $name, self::encodeArguments($arguments));
    }

    /**
     * Encode an arguments array as a JSON object; an empty array becomes "{}", not "[]".
     *
     * @param array<array-key, mixed> $arguments
     *
     * @throws JsonException
     */
    public static function encodeArguments(array $arguments): string
    {
        return json_encode($arguments === [] ? new stdClass() : $arguments, self::JSON_FLAGS);
    }

    /**
     * Normalise provider-supplied arguments to JSON text. OpenAI sends a JSON
     * string; Ollama and some compatible servers send a decoded object. Bad
     * input is passed through, not repaired, so the agent can report it to the model.
     */
    public static function argumentsToJson(mixed $arguments): string
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        if ($arguments === null) {
            return '{}';
        }

        try {
            return is_array($arguments)
                ? self::encodeArguments($arguments)
                : json_encode($arguments, self::JSON_FLAGS);
        } catch (JsonException) {
            return 'null'; // rejected downstream as "not a JSON object"
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException when the arguments are not valid JSON
     * @throws InvalidArgumentException when the arguments are valid JSON but not an object
     */
    public function decodedArguments(): array
    {
        $raw = trim($this->arguments);

        if ($raw === '') {
            return [];
        }

        if (!json_decode($raw, false, 512, JSON_THROW_ON_ERROR) instanceof stdClass) {
            throw new InvalidArgumentException('Tool arguments must be a JSON object.');
        }

        /** @var array<string, mixed> */
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
