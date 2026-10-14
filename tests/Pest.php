<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test runs on Tests\TestCase, which blocks real HTTP requests
| (see TestCase::setUp). Feature tests also get a fresh in-memory
| SQLite database for each test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Fake Claude responses
|--------------------------------------------------------------------------
*/

/**
 * A valid record_triage tool input, with optional overrides.
 */
function triageInput(array $overrides = []): array
{
    return [
        'category' => 'refill',
        'urgency' => 'low',
        'summary' => 'Patient is almost out of their medication and requests a refill.',
        'draft_reply' => 'Thank you for your message. We have received your refill request and a member of the care team will review it and follow up with you.',
        'confidence' => 0.92,
        ...$overrides,
    ];
}

/**
 * A Messages API response body in the real shape: an (empty) adaptive
 * thinking block followed by the tool call.
 */
function claudeResponse(?array $input = null, array $overrides = []): array
{
    return [
        'id' => 'msg_test_0001',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'tool_use', 'id' => 'toolu_test_0001', 'name' => 'record_triage', 'input' => $input ?? triageInput()],
        ],
        'stop_reason' => 'tool_use',
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 812, 'output_tokens' => 143],
        ...$overrides,
    ];
}

function fakeClaude(?array $input = null, array $overrides = [], int $status = 200): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response(claudeResponse($input, $overrides), $status),
    ]);
}
