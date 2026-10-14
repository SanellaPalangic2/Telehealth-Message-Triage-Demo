<?php

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Enums\ReviewAction;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Models\ReviewDecision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the seeder creates 38 new messages', function () {
    $this->seed(DatabaseSeeder::class);

    expect(PatientMessage::count())->toBe(38)
        ->and(PatientMessage::where('status', MessageStatus::New)->count())->toBe(38)
        ->and(PatientMessage::whereNull('received_at')->count())->toBe(0);
});

test('seeding again never duplicates the demo data', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(PatientMessage::count())->toBe(38);
});

test('message status is cast to an enum', function () {
    $message = PatientMessage::factory()->create();

    expect($message->fresh()->status)->toBe(MessageStatus::New);
});

test('a message links to its analyses, decisions and audit logs', function () {
    $message = PatientMessage::factory()->create();

    $analysis = AiAnalysis::create([
        'patient_message_id' => $message->id,
        'redacted_text' => 'Need a refill. Call [PHONE].',
        'category' => 'refill',
        'urgency' => 'low',
        'summary' => 'Patient requests a refill.',
        'draft_reply' => 'Thanks for reaching out. A clinician will review your request.',
        'confidence' => 0.92,
        'requires_review' => false,
        'raw_output' => ['category' => 'refill'],
        'model' => 'test-model',
        'prompt_version' => 'abc123',
        'response_id' => 'msg_test',
        'latency_ms' => 850,
        'input_tokens' => 400,
        'output_tokens' => 120,
    ]);

    ReviewDecision::create([
        'patient_message_id' => $message->id,
        'ai_analysis_id' => $analysis->id,
        'action' => ReviewAction::Approved,
        'final_reply' => $analysis->draft_reply,
        'reviewer' => 'Demo Reviewer',
    ]);

    AuditLog::record(AuditActor::Human, 'review.approved', $message);

    $message->refresh();

    expect($message->latestAiAnalysis->id)->toBe($analysis->id)
        ->and($message->latestAiAnalysis->confidence)->toBe(0.92)
        ->and($message->latestAiAnalysis->raw_output)->toBe(['category' => 'refill'])
        ->and($message->reviewDecisions->first()->action)->toBe(ReviewAction::Approved)
        ->and($message->auditLogs->first()->actor)->toBe(AuditActor::Human);
});

describe('audit logs are append-only', function () {
    test('they can be created', function () {
        $log = AuditLog::record(AuditActor::Rule, 'rule.red_flag', details: ['rule' => 'chest_pain']);

        expect($log->exists)->toBeTrue()
            ->and($log->created_at)->not->toBeNull()
            ->and($log->details)->toBe(['rule' => 'chest_pain']);
    });

    test('the model refuses updates', function () {
        $log = AuditLog::record(AuditActor::Ai, 'ai.suggested');

        $log->update(['action' => 'tampered']);
    })->throws(LogicException::class, 'append-only');

    test('the model refuses deletes', function () {
        AuditLog::record(AuditActor::Ai, 'ai.suggested')->delete();
    })->throws(LogicException::class, 'append-only');

    test('the database refuses raw updates', function () {
        AuditLog::record(AuditActor::Ai, 'ai.suggested');

        DB::table('audit_logs')->update(['action' => 'tampered']);
    })->throws(QueryException::class, 'append-only');

    test('the database refuses raw deletes', function () {
        AuditLog::record(AuditActor::Ai, 'ai.suggested');

        DB::table('audit_logs')->delete();
    })->throws(QueryException::class, 'append-only');
});
