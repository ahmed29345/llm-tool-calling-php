<?php

/**
 * School assistant demo: the same question, asked by different roles, gets different tools.
 *
 *   php examples/school-assistant.php [teacher|accountant|principal] ["question"]
 *
 * Provider selection (LLM_PROVIDER overrides the default):
 *   OpenAI or compatible   OPENAI_API_KEY=...  [OPENAI_MODEL=gpt-4o-mini] [OPENAI_BASE_URL=https://api.openai.com/v1]
 *   Ollama (default)       [OLLAMA_MODEL=llama3.1] [OLLAMA_HOST=http://localhost:11434]
 *   Offline                LLM_PROVIDER=fake  (a scripted "model" that always tries the finance tool)
 */

declare(strict_types=1);

use Ahmed29345\LlmToolCalling\Actor;
use Ahmed29345\LlmToolCalling\Agent;
use Ahmed29345\LlmToolCalling\Chat\ChatProvider;
use Ahmed29345\LlmToolCalling\Chat\ChatResponse;
use Ahmed29345\LlmToolCalling\Chat\Message;
use Ahmed29345\LlmToolCalling\Chat\ToolCall;
use Ahmed29345\LlmToolCalling\Examples\School\AttendanceSummaryTool;
use Ahmed29345\LlmToolCalling\Examples\School\ClassRosterTool;
use Ahmed29345\LlmToolCalling\Examples\School\UnpaidInvoicesTool;
use Ahmed29345\LlmToolCalling\Exception\ProviderException;
use Ahmed29345\LlmToolCalling\Provider\FakeProvider;
use Ahmed29345\LlmToolCalling\Provider\OllamaProvider;
use Ahmed29345\LlmToolCalling\Provider\OpenAiProvider;
use Ahmed29345\LlmToolCalling\Tool;
use Ahmed29345\LlmToolCalling\ToolRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

require __DIR__ . '/../vendor/autoload.php';

$actors = [
    'teacher' => new Actor('teacher-7', ['teacher'], ['attendance.view']),
    'accountant' => new Actor('accountant-2', ['accountant'], ['finance.view']),
    'principal' => new Actor('principal-1', ['principal'], ['attendance.*', 'finance.*']),
];

$role = $argv[1] ?? 'teacher';
$question = $argv[2] ?? 'How was attendance in Grade 3A this week, and which of those families still owe fees?';

if (!isset($actors[$role])) {
    fwrite(STDERR, sprintf("Unknown role \"%s\". Use one of: %s\n", $role, implode(', ', array_keys($actors))));
    exit(1);
}

$actor = $actors[$role];

$registry = new ToolRegistry([
    new ClassRosterTool(),
    new AttendanceSummaryTool(),
    new UnpaidInvoicesTool(),
]);

$env = static fn (string $name, string $default = ''): string => is_string(getenv($name)) && getenv($name) !== ''
    ? (string) getenv($name)
    : $default;

$providerName = $env('LLM_PROVIDER', $env('OPENAI_API_KEY') !== '' ? 'openai' : 'ollama');
$http = new Client(['timeout' => 120]);
$factory = new HttpFactory();

$provider = match ($providerName) {
    'openai' => new OpenAiProvider(
        $http,
        $factory,
        $factory,
        model: $env('OPENAI_MODEL', 'gpt-4o-mini'),
        apiKey: $env('OPENAI_API_KEY'),
        baseUrl: $env('OPENAI_BASE_URL', OpenAiProvider::DEFAULT_BASE_URL),
        options: ['temperature' => 0],
    ),
    'ollama' => new OllamaProvider(
        $http,
        $factory,
        $factory,
        model: $env('OLLAMA_MODEL', 'llama3.1'),
        baseUrl: $env('OLLAMA_HOST', OllamaProvider::DEFAULT_BASE_URL),
        options: ['temperature' => 0],
    ),
    'fake' => scriptedModel(),
    default => null,
};

if (!$provider instanceof ChatProvider) {
    fwrite(STDERR, "LLM_PROVIDER must be openai, ollama or fake.\n");
    exit(1);
}

$agent = new Agent(
    $provider,
    $registry,
    systemPrompt: 'You are a school office assistant. Answer only from tool results. '
        . 'If a tool is not available to you, say that you cannot access that information.',
    maxSteps: 6,
);

printf("Provider: %s\nActor:    %s (%s)\nTools:    %s\nQuestion: %s\n\n", $providerName, $actor->id, $role, implode(', ', array_map(
    static fn (Tool $tool): string => $tool->name(),
    $registry->forActor($actor),
)), $question);

try {
    $result = $agent->ask($actor, $question);
} catch (ProviderException $e) {
    fwrite(STDERR, 'Provider error: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($result->invocations as $invocation) {
    printf("  -> %s %s  [%s]\n", $invocation->call->name, $invocation->call->arguments, $invocation->outcome->value);
}

printf("\n%s\n", $result->answer ?? sprintf('(stopped: %s after %d steps)', $result->stopReason->value, $result->steps));

/**
 * A stand-in model that always asks for the attendance and the finance tool,
 * whatever it was offered, then reports what came back.
 */
function scriptedModel(): FakeProvider
{
    return new FakeProvider(
        ChatResponse::toolCalls(
            ToolCall::withArguments('call_1', 'attendance_summary', ['class' => 'Grade 3A']),
            ToolCall::withArguments('call_2', 'unpaid_invoices', ['class' => 'Grade 3A']),
        ),
        static function (array $messages): ChatResponse {
            $results = array_filter($messages, static fn (Message $message): bool => $message->toolName !== null);
            $lines = array_map(
                static fn (Message $message): string => sprintf('%s returned %s', $message->toolName, $message->content),
                $results,
            );

            return ChatResponse::text("Scripted summary of the tool results:\n" . implode("\n", $lines));
        },
    );
}
