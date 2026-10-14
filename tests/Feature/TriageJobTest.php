<?php

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Jobs\TriageMessage;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Services\TriagePipeline;
use App\Services\TriagePrompt;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PatientMessageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

function receive(string $body = 'Hi, this is Maria Delgado. Refill my lisinopril please. Call (201) 555-0142.'): PatientMessage
{
    return PatientMessage::create(['body' => $body, 'received_at' => now()]);
}

/** A pending message whose analysis came from the given prompt version and model. */
function messageWithAnalysis(?string $promptVersion = null, string $model = 'claude-opus-5-5'): PatientMessage
{
    $message = PatientMessage::factory()->create(['status' => MessageStatus::PendingReview]);
    AiAnalysis::create([
        'patient_message_id' => $message->id,
        'redacted_text' => 'Refill please',
        'category' => 'refill', 'urgency' => 'low', 'confidence' => 0.9, 'requires_review' => false,
        'summary' => 'Refill request.', 'draft_reply' => 'Thanks, the care team will follow up.',
        'raw_output' => [], 'model' => $model, 'prompt_version' => $promptVersion ?? TriagePrompt::version(),
        'response_id' => 'msg_old', 'latency_ms' => 1, 'input_tokens' => 1, 'output_tokens' => 1,
    ]);

    return $message;
}

describe('automatic triage on creation', function () {
    test('creating a message dispatches a TriageMessage job', function () {
        Queue::fake();

        $message = receive();

        Queue::assertPushed(TriageMessage::class, fn ($job) => $job->message->is($message));
    });

    test('seeding the demo data never dispatches triage jobs', function () {
        Queue::fake();

        $this->seed(DatabaseSeeder::class);
        $this->seed(PatientMessageSeeder::class); // also when the seeder runs on its own

        expect(PatientMessage::count())->toBe(30);
        Queue::assertNothingPushed();
    });

    test('messages created already triaged do not dispatch', function () {
        Queue::fake();

        PatientMessage::factory()->create(['status' => MessageStatus::Approved]);

        Queue::assertNothingPushed();
    });

    test('the job runs through the real database queue and worker', function () {
        config(['queue.default' => 'database']);
        fakeClaude();

        $message = receive();

        expect(DB::table('jobs')->count())->toBe(1)
            ->and($message->fresh()->status)->toBe(MessageStatus::New);
        Http::assertNothingSent();

        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();

        expect($message->fresh()->status)->toBe(MessageStatus::PendingReview)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(AiAnalysis::sole()->patient_message_id)->toBe($message->id);
        Http::assertSentCount(1);
    });

    test('the job runs the full pipeline and logs each step', function () {
        config(['queue.default' => 'sync']);
        fakeClaude();

        $message = receive();

        expect($message->fresh()->status)->toBe(MessageStatus::PendingReview);

        $logs = $message->auditLogs()->orderBy('id')->get();
        expect($logs->map(fn ($l) => [$l->actor, $l->action])->all())->toBe([
            [AuditActor::Rule, 'rules.passed'],
            [AuditActor::Ai, 'ai.suggested'],
        ])
            ->and($logs[1]->details['redactions'])->toBe(['PHONE' => 1, 'NAME' => 1]);

        // Redaction happened before the AI call.
        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'Delgado')
            && ! str_contains($request->body(), '555-0142')
            && str_contains($request->body(), '[NAME]'));
    });

    test('a red flag goes to the urgent queue without calling the AI', function () {
        config(['queue.default' => 'sync']);
        fakeClaude();

        $message = receive('I have crushing chest pain and my left arm is numb');

        expect($message->fresh()->status)->toBe(MessageStatus::UrgentReview)
            ->and($message->auditLogs()->sole()->actor)->toBe(AuditActor::Rule);
        Http::assertNothingSent();
    });
});

describe('failures mark the message as failed, with no fake output', function () {
    function expectFailedWithoutOutput(PatientMessage $message, string $reason): void
    {
        $message->refresh();

        expect($message->status)->toBe(MessageStatus::AiFailed)
            ->and(AiAnalysis::count())->toBe(0)
            ->and($message->auditLogs()->where('action', 'ai.failed')->sole()->details['reason'])->toBe($reason);

        test()->get(route('messages.show', $message))
            ->assertSee('AI triage failed.')
            ->assertSee('Re-run triage')
            ->assertDontSee('AI suggestion')
            ->assertDontSee('Approve draft as written');
    }

    test('invalid AI output', function () {
        config(['queue.default' => 'sync']);
        fakeClaude(triageInput(['category' => 'emergency']));

        expectFailedWithoutOutput(receive(), 'invalid_output');
    });

    test('a draft reply that breaks policy', function () {
        config(['queue.default' => 'sync']);
        fakeClaude(triageInput(['draft_reply' => 'Your visit costs $40.']));

        expectFailedWithoutOutput(receive(), 'invalid_output');
    });

    test('an API error', function () {
        config(['queue.default' => 'sync']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'Down']], 500)]);

        expectFailedWithoutOutput(receive(), 'http_error');
    });

    test('a refusal', function () {
        config(['queue.default' => 'sync']);
        fakeClaude(overrides: ['content' => [], 'stop_reason' => 'refusal']);

        expectFailedWithoutOutput(receive(), 'refused');
    });

    test('an unexpected crash inside the job', function () {
        config(['queue.default' => 'database']);
        $this->mock(TriagePipeline::class)
            ->shouldReceive('process')
            ->andThrow(new RuntimeException('database exploded at /internal/path'));

        $message = receive();
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);

        expectFailedWithoutOutput($message, 'job_failed');
        expect(DB::table('failed_jobs')->count())->toBe(1)
            ->and($message->auditLogs()->where('action', 'ai.failed')->sole()->details['error'])
            ->not->toContain('/internal/path');
    });
});

describe('the job', function () {
    test('skips messages that were already triaged', function () {
        fakeClaude();
        $message = PatientMessage::factory()->create(['status' => MessageStatus::Approved]);

        TriageMessage::dispatchSync($message);

        expect($message->fresh()->status)->toBe(MessageStatus::Approved);
        Http::assertNothingSent();
    });

    test('processes queued messages', function () {
        fakeClaude();
        $message = PatientMessage::factory()->create(['status' => MessageStatus::Queued]);

        TriageMessage::dispatchSync($message);

        expect($message->fresh()->status)->toBe(MessageStatus::PendingReview);
    });
});

describe('re-run triage', function () {
    test('re-queues a failed message and audits who asked', function () {
        Queue::fake();
        $message = PatientMessage::factory()->create(['status' => MessageStatus::AiFailed]);

        $this->post(route('messages.rerun', $message))->assertSessionHas('success');

        expect($message->fresh()->status)->toBe(MessageStatus::Queued);
        $log = AuditLog::where('action', 'triage.queued')->sole();
        expect($log->actor)->toBe(AuditActor::Human)
            ->and($log->details['reason'])->toBe('rerun_failed')
            ->and($log->details['previous_status'])->toBe('ai_failed');
        Queue::assertPushed(TriageMessage::class, 1);
    });

    test('a re-run that succeeds keeps the earlier failure in the audit trail', function () {
        fakeClaude();
        $message = PatientMessage::factory()->create(['status' => MessageStatus::AiFailed]);
        AuditLog::record(AuditActor::Ai, 'ai.failed', $message, ['reason' => 'http_error', 'error' => 'Down']);

        config(['queue.default' => 'sync']);
        $this->post(route('messages.rerun', $message));

        expect($message->fresh()->status)->toBe(MessageStatus::PendingReview)
            ->and($message->auditLogs()->orderBy('id')->pluck('action')->all())
            ->toBe(['ai.failed', 'triage.queued', 'rules.passed', 'ai.suggested']);
    });

    test('re-runs a stale suggestion and keeps the old one in history', function () {
        fakeClaude();
        $message = messageWithAnalysis(promptVersion: 'old000000000');

        $this->get(route('messages.show', $message))
            ->assertSee('This suggestion may be out of date.')
            ->assertSee('Re-run triage');

        config(['queue.default' => 'sync']);
        $this->post(route('messages.rerun', $message))->assertSessionHas('success');

        $message->refresh();
        expect($message->status)->toBe(MessageStatus::PendingReview)
            ->and($message->aiAnalyses()->count())->toBe(2)
            ->and($message->latestAiAnalysis->prompt_version)->toBe(TriagePrompt::version());
    });

    test('a suggestion from a different model is stale too', function () {
        $message = messageWithAnalysis(model: 'some-older-model');

        $this->get(route('messages.show', $message))->assertSee('Re-run triage');
    });

    test('is refused for fresh, urgent, or decided messages', function (MessageStatus|string $state) {
        Queue::fake();
        $message = $state === 'fresh'
            ? messageWithAnalysis()
            : PatientMessage::factory()->create(['status' => $state]);

        $this->get(route('messages.show', $message))->assertDontSee('Re-run triage');
        $this->post(route('messages.rerun', $message))->assertSessionHas('error');

        Queue::assertNothingPushed();
    })->with([
        'fresh suggestion' => 'fresh',
        'urgent' => MessageStatus::UrgentReview,
        'approved' => MessageStatus::Approved,
        'rejected' => MessageStatus::Rejected,
        'already queued' => MessageStatus::Queued,
    ]);
});

describe('queued state in the UI', function () {
    test('shows a waiting banner and auto-refreshes', function () {
        $message = PatientMessage::factory()->create(['status' => MessageStatus::Queued]);

        $this->get(route('messages.show', $message))
            ->assertSee('Queued for triage.')
            ->assertSee('<meta http-equiv="refresh" content="5">', false);
    });

    test('pages that are not waiting do not auto-refresh', function () {
        $message = messageWithAnalysis();

        $this->get(route('messages.show', $message))->assertDontSee('http-equiv="refresh"', false);
    });
});

describe('triage all pending', function () {
    test('queues every untriaged message', function () {
        $this->seed(DatabaseSeeder::class);
        Queue::fake();

        $this->get(route('inbox'))->assertSee('Triage all pending (30)');

        $this->post(route('triage.pending'))
            ->assertRedirect(route('inbox'))
            ->assertSessionHas('success', 'Queued 30 messages for triage.');

        expect(PatientMessage::where('status', MessageStatus::Queued)->count())->toBe(30)
            ->and(AuditLog::where('action', 'triage.queued')->where('actor', AuditActor::Human)->count())->toBe(30);
        Queue::assertPushed(TriageMessage::class, 30);

        $this->get(route('inbox'))
            ->assertDontSee('Triage all pending')
            ->assertSee('30 messages queued for triage');
    });

    test('says so when there is nothing to queue', function () {
        messageWithAnalysis();

        $this->post(route('triage.pending'))->assertSessionHas('error', 'There are no untriaged messages.');
    });
});

describe('triage:run', function () {
    test('requires --pending or --id', function () {
        $this->artisan('triage:run')->expectsOutputToContain('--pending')->assertFailed();
    });

    test('--pending --queue dispatches jobs instead of calling the API', function () {
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Http::fake();

        $this->artisan('triage:run --pending --queue')
            ->expectsOutputToContain('Queued 30')
            ->assertSuccessful();

        Queue::assertPushed(TriageMessage::class, 30);
        Http::assertNothingSent();
    });

    test('--pending also picks up messages stuck in the queue', function () {
        fakeClaude();
        PatientMessage::factory()->create(['status' => MessageStatus::Queued]);

        $this->artisan('triage:run --pending')->assertSuccessful();

        expect(PatientMessage::sole()->status)->toBe(MessageStatus::PendingReview);
    });

    test('--id skips messages that were already triaged', function () {
        Http::fake();
        $message = messageWithAnalysis();

        $this->artisan('triage:run', ['--id' => [$message->id]])
            ->expectsOutputToContain('already triaged')
            ->assertSuccessful();

        Http::assertNothingSent();
    });
});
