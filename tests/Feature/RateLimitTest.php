<?php

use App\Enums\MessageStatus;
use App\Jobs\TriageMessage;
use App\Models\PatientMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    fakeClaude();
});

function newMessages(int $count)
{
    return PatientMessage::factory()->count($count)->create(['body' => 'Refill please']);
}

test('queueing AI triage is limited per visitor per minute', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 2]);
    [$a, $b, $c] = newMessages(3);
    Queue::fake();

    $this->post(route('messages.triage', $a))->assertSessionHas('success');
    $this->post(route('messages.triage', $b))->assertSessionHas('success');
    $this->post(route('messages.triage', $c))
        ->assertRedirect(route('messages.show', $c))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Slow down'));

    expect($c->fresh()->status)->toBe(MessageStatus::New);
    Queue::assertPushed(TriageMessage::class, 2);
});

test('a different visitor has their own per-minute allowance', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1]);
    [$a, $b] = newMessages(2);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post(route('messages.triage', $a))->assertSessionHas('success');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post(route('messages.triage', $b))->assertSessionHas('success');
});

test('the limit resets after a minute', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1]);
    [$a, $b] = newMessages(2);

    $this->post(route('messages.triage', $a))->assertSessionHas('success');
    $this->travel(61)->seconds();
    $this->post(route('messages.triage', $b))->assertSessionHas('success');
});

test('re-run shares the per-visitor AI limit', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1]);
    [$a, $b] = PatientMessage::factory()->count(2)->create(['status' => MessageStatus::AiFailed]);

    $this->post(route('messages.rerun', $a))->assertSessionHas('success');
    $this->post(route('messages.rerun', $b))->assertSessionHas('error', fn ($m) => str_contains($m, 'Slow down'));
});

test('"Triage all pending" is limited per visitor and globally', function () {
    config(['triage.rate_limits.bulk_per_hour_per_ip' => 1, 'triage.rate_limits.bulk_per_day_global' => 2]);
    newMessages(2);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post(route('triage.pending'))->assertSessionHas('success');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post(route('triage.pending'))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'used recently'));

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post(route('triage.pending')); // 2nd global use
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->post(route('triage.pending'))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'daily limit'));
});

test('the daily AI budget caps queued AI calls across everyone, per call', function () {
    config(['triage.rate_limits.ai_per_day_global' => 2]);
    [$a, $b, $c] = newMessages(3);

    foreach ([$a, $b, $c] as $message) {
        TriageMessage::dispatchSync($message);
    }

    expect($a->fresh()->status)->toBe(MessageStatus::PendingReview)
        ->and($b->fresh()->status)->toBe(MessageStatus::PendingReview)
        ->and($c->fresh()->status)->toBe(MessageStatus::AiFailed)
        ->and($c->auditLogs()->where('action', 'ai.failed')->sole()->details['reason'])->toBe('budget_exhausted');

    Http::assertSentCount(2);
});

test('red-flag rules still run when the AI budget is used up', function () {
    config(['triage.rate_limits.ai_per_day_global' => 0]);
    $message = PatientMessage::factory()->create(['body' => 'I have crushing chest pain']);

    TriageMessage::dispatchSync($message);

    expect($message->fresh()->status)->toBe(MessageStatus::UrgentReview);
    Http::assertNothingSent();
});

test('review actions are rate limited too', function () {
    config(['triage.rate_limits.reviews_per_minute_per_ip' => 1]);
    $message = PatientMessage::factory()->create(['status' => MessageStatus::UrgentReview]);

    $this->post(route('messages.edit', $message), ['reviewer' => 'A', 'final_reply' => 'Call 911.'])
        ->assertSessionHas('success');
    $this->post(route('messages.edit', $message), ['reviewer' => 'A', 'final_reply' => 'Again'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many actions'));
});

test('the CLI is not rate limited or budgeted', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1, 'triage.rate_limits.ai_per_day_global' => 1]);
    newMessages(3);

    $this->artisan('triage:run --pending')->assertSuccessful();

    Http::assertSentCount(3);
});
