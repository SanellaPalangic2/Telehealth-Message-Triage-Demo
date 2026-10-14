<?php

namespace App\Services\Ai;

use App\Services\TriagePrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The ONLY class that calls Claude.
 *
 * It sends redacted text only (via TriagePrompt), requires a schema-checked
 * tool call, validates the result server-side, and throws AiTriageException
 * on any failure. It never invents fallback output and never writes to the
 * database: the caller decides what to store.
 */
class AiTriageService
{
    /** Statuses worth retrying: rate limited, server error, overloaded. */
    private const RETRYABLE = [429, 500, 502, 503, 504, 529];

    public function __construct(
        private TriagePrompt $prompt,
        private AiOutputValidator $validator,
    ) {}

    /**
     * @throws AiTriageException
     */
    public function analyze(string $messageBody): AiTriageResult
    {
        $config = config('services.anthropic');

        if (blank($config['key']) || blank($config['model'])) {
            throw new AiTriageException(
                AiTriageException::NOT_CONFIGURED,
                'AI triage is not configured: set ANTHROPIC_API_KEY and ANTHROPIC_MODEL in .env.',
            );
        }

        $payload = $this->prompt->build($messageBody);

        $body = [
            'model' => $config['model'],
            'max_tokens' => $config['max_tokens'],
            'system' => $payload->system,
            'messages' => $payload->messages,
            'tools' => [TriagePrompt::tool()],
            // Current models reject a forced tool_choice; "auto" + strict
            // schema + the system instruction gets the same result, and we
            // reject any response that doesn't call the tool.
            'tool_choice' => ['type' => 'auto', 'disable_parallel_tool_use' => true],
            'output_config' => ['effort' => $config['effort']],
        ];

        $started = hrtime(true);
        $response = $this->send($config, $body);
        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $json = $response->json();

        $stopReason = $json['stop_reason'] ?? null;
        if ($stopReason === 'refusal') {
            throw new AiTriageException(AiTriageException::REFUSED, 'The AI declined to process this message.');
        }
        if ($stopReason === 'max_tokens') {
            throw new AiTriageException(AiTriageException::TRUNCATED, 'The AI response was cut off before it finished.');
        }

        $toolCall = collect($json['content'] ?? [])
            ->first(fn ($block) => ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === TriagePrompt::TOOL_NAME);

        if ($toolCall === null) {
            throw new AiTriageException(
                AiTriageException::NO_TOOL_CALL,
                'The AI did not return a structured triage result.',
                ['stop_reason' => $stopReason],
            );
        }

        $output = $this->validator->validate($toolCall['input'] ?? null);

        return new AiTriageResult(
            output: $output,
            rawOutput: $toolCall['input'],
            redaction: $payload->redaction,
            model: $json['model'] ?? $config['model'],
            promptVersion: TriagePrompt::version(),
            responseId: (string) ($json['id'] ?? ''),
            latencyMs: $latencyMs,
            inputTokens: (int) ($json['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $body
     *
     * @throws AiTriageException
     */
    private function send(array $config, array $body): Response
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $config['key'],
                'anthropic-version' => $config['version'],
            ])
                ->acceptJson()
                ->timeout($config['timeout'])
                ->retry(
                    3,
                    fn (int $attempt) => $attempt * 1000,
                    fn ($e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), self::RETRYABLE, true)),
                    throw: false,
                )
                ->post($config['url'], $body);
        } catch (ConnectionException) {
            throw new AiTriageException(
                AiTriageException::CONNECTION_ERROR,
                'Could not reach the Anthropic API (network error or timeout).',
            );
        }

        if ($response->successful()) {
            return $response;
        }

        $type = $response->json('error.type') ?? 'unknown_error';
        $apiMessage = (string) ($response->json('error.message') ?? '');

        $hint = match ($response->status()) {
            401 => 'The API key was rejected. Check ANTHROPIC_API_KEY.',
            403 => 'This API key is not allowed to use this model or feature.',
            404 => 'The model was not found. Check ANTHROPIC_MODEL.',
            429 => 'Rate limited by the Anthropic API. Try again shortly.',
            529 => 'The Anthropic API is overloaded. Try again shortly.',
            default => $response->serverError() ? 'The Anthropic API had a server error.' : 'The request was rejected.',
        };

        throw new AiTriageException(
            AiTriageException::HTTP_ERROR,
            $this->scrub("AI request failed (HTTP {$response->status()}, {$type}). {$hint} {$apiMessage}", $config['key']),
            ['status' => $response->status(), 'error_type' => $type],
        );
    }

    /** Defense in depth: never let the key appear in an error message. */
    private function scrub(string $text, string $key): string
    {
        return trim(str_replace($key, '[REDACTED]', $text));
    }
}
