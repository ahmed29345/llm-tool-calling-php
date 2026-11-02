<?php

declare(strict_types=1);

namespace Ahmed29345\LlmToolCalling\Provider;

use Ahmed29345\LlmToolCalling\Tool;
use stdClass;

/**
 * Builds the {"type": "function", "function": {...}} tool definition shared by
 * the OpenAI Chat Completions API and Ollama's /api/chat.
 *
 * @internal
 */
final class FunctionDefinition
{
    /**
     * @return array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public static function fromTool(Tool $tool): array
    {
        $parameters = $tool->parameters();
        $parameters['type'] ??= 'object';

        // An empty PHP array would encode as [], which providers reject as a schema.
        if (!isset($parameters['properties']) || $parameters['properties'] === []) {
            $parameters['properties'] = new stdClass();
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $parameters,
            ],
        ];
    }
}
