<?php

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Services\Ai\AiTriageException;
use App\Services\Ai\AiTriageService;
use App\Services\TriageOutcome;
use App\Services\TriagePipeline;
use App\Services\TriagePrompt;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

const PII_MESSAGE = "Hi, this is Maria Delgado, DOB 03/14/1985. I'm almost out of my lisinopril 10mg. Call (201) 555-0142.";

beforeEach(fn () => Sleep::fake());

function triage(string $body = PII_MESSAGE): array
{
    $message = PatientMessage::factory()->create(['body' => $body]);
    $outcome = app(TriagePipeline::class)->process($message);

    return [$outcome, $message->fresh()];
}

describe('the request sent to Claude', function () {
    test('uses the configured key, version, model, and a strict required tool', function () {
        fakeClaude();

        triage();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->method() === 'POST'
                && $request->hasHeader('x-api-key', 'sk-ant-test-fake-key-0000')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $body['model'] === 'claude-opus-5-5'
                && $body['tools'] === [TriagePrompt::tool()]
                && $body['tools'][0]['strict'] === true
                && $body['tool_choice'] === ['type' => 'auto', 'disable_parallel_tool_use' => true]
                && str_contains($body['system'], 'record_triage');
        });
    });

    test('contains only redacted text', function () {
        fakeClaude();

        triage();

        Http::assertSent(function (Request $request) {
            $sent = $request->body();

            foreach (['Maria', 'Delgado', '03/14/1985', '555-0142'] as $pii) {
                expect($sent)->not->toContain($pii);
            }

            return str_contains($sent, '[NAME]') && str_contains($sent, '[PHONE]');
        });
    });

    test('is never made for red-flag messages', function () {
        fakeClaude();

        [$outcome] = triage('Crushing chest pain and my left arm is numb');

        expect($outcome)->toBe(TriageOutcome::Urgent);
        Http::assertNothingSent();
    });
});

describe('a valid response', function () {
    test('is stored as a suggestion awaiting human review', function () {
        fakeClaude();

        [$outcome, $message] = triage();

        expect($outcome)->toBe(TriageOutcome::Suggested)
            ->and($message->status)->toBe(MessageStatus::PendingReview);

        $analysis = AiAnalysis::sole();
        expect($analysis->category)->toBe('refill')
            ->and($analysis->urgency)->toBe('low')
            ->and($analysis->confidence)->toBe(0.92)
            ->and($analysis->requires_review)->toBeFalse()
            ->and($analysis->response_id)->toBe('msg_test_0001')
            ->and($analysis->model)->toBe('claude-opus-5-5')
            ->and($analysis->prompt_version)->toBe(TriagePrompt::version())
            ->and($analysis->input_tokens)->toBe(812)
            ->and($analysis->output_tokens)->toBe(143)
            ->and($analysis->latency_ms)->toBeGreaterThanOrEqual(0)
            ->and($analysis->raw_output)->toBe(triageInput());
    });

    test('stores only the redacted text', function () {
        fakeClaude();

        triage();

        $analysis = AiAnalysis::sole();
        expect($analysis->redacted_text)->not->toContain('Maria')
            ->and($analysis->redacted_text)->not->toContain('555-0142')
            ->and($analysis->redacted_text)->toContain('[NAME]');
    });

    test('is audited: RULE passed, then AI suggested', function () {
        fakeClaude();

        [, $message] = triage();

        $logs = $message->auditLogs()->orderBy('id')->get();
        expect($logs->map(fn ($l) => [$l->actor, $l->action])->all())->toBe([
            [AuditActor::Rule, 'rules.passed'],
            [AuditActor::Ai, 'ai.suggested'],
        ])
            ->and($logs[1]->details['redactions'])->toBe(['PHONE' => 1, 'DOB' => 1, 'NAME' => 1])
            ->and($logs[1]->details['response_id'])->toBe('msg_test_0001');
    });

    test('with low confidence requires review', function (float $confidence, bool $requiresReview) {
        fakeClaude(triageInput(['confidence' => $confidence]));

        triage();

        expect(AiAnalysis::sole()->requires_review)->toBe($requiresReview);
    })->with([
        [0.59, true],
        [0.6, false],
        [0.1, true],
    ]);

    test('rated high urgency by the AI requires review', function () {
        fakeClaude(triageInput(['urgency' => 'high', 'confidence' => 0.95]));

        triage();

        expect(AiAnalysis::sole()->requires_review)->toBeTrue();
    });
});

describe('a failure', function () {
    function expectAiFailure(string $reason, string $messageFragment): void
    {
        [$outcome, $message] = triage();

        expect($outcome)->toBe(TriageOutcome::AiFailed)
            ->and($message->status)->toBe(MessageStatus::AiFailed)
            ->and(AiAnalysis::count())->toBe(0);

        $log = $message->auditLogs()->where('action', 'ai.failed')->sole();
        expect($log->actor)->toBe(AuditActor::Ai)
            ->and($log->details['reason'])->toBe($reason)
            ->and($log->details['error'])->toContain($messageFragment);
    }

    test('invalid output is rejected, never stored as a suggestion', function () {
        fakeClaude(triageInput(['category' => 'emergency']));

        expectAiFailure(AiTriageException::INVALID_OUTPUT, 'failed validation');
    });

    test('a reply that breaks policy is rejected', function () {
        fakeClaude(triageInput(['draft_reply' => 'We guarantee a refill by Friday.']));

        expectAiFailure(AiTriageException::INVALID_OUTPUT, 'guarantee');
    });

    test('a plain-text answer with no tool call is rejected', function () {
        fakeClaude(overrides: [
            'content' => [['type' => 'text', 'text' => 'This looks like a refill request.']],
            'stop_reason' => 'end_turn',
        ]);

        expectAiFailure(AiTriageException::NO_TOOL_CALL, 'did not return a structured');
    });

    test('a refusal is reported', function () {
        fakeClaude(overrides: ['content' => [], 'stop_reason' => 'refusal']);

        expectAiFailure(AiTriageException::REFUSED, 'declined');
    });

    test('a truncated response is reported', function () {
        fakeClaude(overrides: ['stop_reason' => 'max_tokens']);

        expectAiFailure(AiTriageException::TRUNCATED, 'cut off');
    });

    test('HTTP errors are reported with a clear hint', function (int $status, string $type, string $hint) {
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['type' => 'error', 'error' => ['type' => $type, 'message' => 'Upstream says no.']],
            $status,
        )]);

        expectAiFailure(AiTriageException::HTTP_ERROR, $hint);
    })->with([
        [400, 'invalid_request_error', 'request was rejected'],
        [401, 'authentication_error', 'API key was rejected'],
        [404, 'not_found_error', 'Check ANTHROPIC_MODEL'],
        [429, 'rate_limit_error', 'Rate limited'],
        [500, 'api_error', 'server error'],
        [529, 'overloaded_error', 'overloaded'],
    ]);

    test('temporary errors are retried before giving up', function () {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['type' => 'overloaded_error']], 529)
            ->push(claudeResponse(), 200),
        ]);

        [$outcome] = triage();

        expect($outcome)->toBe(TriageOutcome::Suggested);
        Http::assertSentCount(2);
    });

    test('client errors are not retried', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'authentication_error']], 401)]);

        triage();

        Http::assertSentCount(1);
    });

    test('a network failure is reported', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        expectAiFailure(AiTriageException::CONNECTION_ERROR, 'Could not reach');
    });

    test('a missing key fails clearly without calling the API', function () {
        config(['services.anthropic.key' => null]);
        fakeClaude();

        expectAiFailure(AiTriageException::NOT_CONFIGURED, 'ANTHROPIC_API_KEY');
        Http::assertNothingSent();
    });
});

describe('the API key', function () {
    test('never appears in errors, audit logs, or the log file', function () {
        $key = 'sk-ant-test-fake-key-0000';
        Log::spy();
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => "invalid x-api-key {$key}"]],
            401,
        )]);

        [, $message] = triage();

        $audit = json_encode($message->auditLogs()->get()->toArray());
        expect($audit)->not->toContain($key)
            ->and($audit)->toContain('[REDACTED]');
        Log::shouldNotHaveReceived('log');
        Log::shouldNotHaveReceived('error');
    });

    test('is not part of the service output', function () {
        fakeClaude();

        $result = app(AiTriageService::class)->analyze('Need a refill please');

        expect(serialize($result))->not->toContain('sk-ant-test-fake-key-0000');
    });
});

test('triage:run --pending runs all 30 seed messages: 4 urgent, 26 suggestions', function () {
    $this->seed(DatabaseSeeder::class);
    fakeClaude();

    $this->artisan('triage:run --pending')->assertSuccessful();

    expect(PatientMessage::where('status', MessageStatus::UrgentReview)->count())->toBe(4)
        ->and(PatientMessage::where('status', MessageStatus::PendingReview)->count())->toBe(26)
        ->and(AiAnalysis::count())->toBe(26)
        ->and(AuditLog::where('actor', AuditActor::Ai)->count())->toBe(26);

    Http::assertSentCount(26);
});
