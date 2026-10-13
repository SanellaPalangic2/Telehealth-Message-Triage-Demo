<?php

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Services\TriageOutcome;
use App\Services\TriagePipeline;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PatientMessageSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::fake());

test('a red flag goes to the urgent queue, is logged as RULE, and never calls the AI', function (array $seed) {
    $message = PatientMessage::factory()->create(['body' => $seed['body']]);

    $outcome = app(TriagePipeline::class)->process($message);

    expect($outcome)->toBe(TriageOutcome::Urgent)
        ->and($message->fresh()->status)->toBe(MessageStatus::UrgentReview);

    $log = AuditLog::sole();
    expect($log->actor)->toBe(AuditActor::Rule)
        ->and($log->action)->toBe('rules.red_flag')
        ->and($log->patient_message_id)->toBe($message->id)
        ->and(array_column($log->details['hits'], 'rule'))->toBe($seed['rules'])
        ->and($log->details['ai_called'])->toBeFalse();

    Http::assertNothingSent();
})->with(fn () => collect(PatientMessageSeeder::data())
    ->where('kind', 'red_flag')
    ->mapWithKeys(fn ($m, $i) => ["seed #{$i}" => [$m]])
    ->all());

test('a routine message is handed on for AI triage without changes', function () {
    $message = PatientMessage::factory()->create(['body' => 'Can I get a refill on my metformin?']);

    $outcome = app(TriagePipeline::class)->process($message);

    expect($outcome)->toBe(TriageOutcome::NeedsAi)
        ->and($message->fresh()->status)->toBe(MessageStatus::New)
        ->and(AuditLog::count())->toBe(0);
});

test('a message that was already triaged is refused', function () {
    $message = PatientMessage::factory()->create(['status' => MessageStatus::UrgentReview]);

    app(TriagePipeline::class)->process($message);
})->throws(LogicException::class, 'already been triaged');

test('triage:process flags exactly the 4 seeded red flags', function () {
    $this->seed(DatabaseSeeder::class);

    $this->artisan('triage:process')
        ->expectsOutputToContain('URGENT')
        ->assertSuccessful();

    expect(PatientMessage::where('status', MessageStatus::UrgentReview)->count())->toBe(4)
        ->and(AuditLog::where('actor', AuditActor::Rule)->count())->toBe(4)
        ->and(PatientMessage::where('status', MessageStatus::New)->count())->toBe(26);

    Http::assertNothingSent();
});

test('triage:process can target specific messages', function () {
    $urgent = PatientMessage::factory()->create(['body' => 'I have chest pain']);
    $other = PatientMessage::factory()->create(['body' => 'I also have chest pain']);

    $this->artisan('triage:process', ['--id' => [$urgent->id]])->assertSuccessful();

    expect($urgent->fresh()->status)->toBe(MessageStatus::UrgentReview)
        ->and($other->fresh()->status)->toBe(MessageStatus::New);
});
