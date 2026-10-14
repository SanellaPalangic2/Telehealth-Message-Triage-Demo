<?php

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    fakeClaude();
});

function newMessages(int $count)
{
    return PatientMessage::factory()->count($count)->create(['body' => 'Refill please']);
}

test('AI triage is limited per visitor per minute', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 2]);
    [$a, $b, $c] = newMessages(3);

    $this->post(route('messages.triage', $a))->assertSessionHas('success');
    $this->post(route('messages.triage', $b))->assertSessionHas('success');
    $this->post(route('messages.triage', $c))
        ->assertRedirect(route('messages.show', $c))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Slow down'));

    expect($c->fresh()->status)->toBe(MessageStatus::New);
    Http::assertSentCount(2);
});

test('a different visitor has their own per-minute allowance', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1]);
    [$a, $b] = newMessages(2);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post(route('messages.triage', $a))->assertSessionHas('success');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post(route('messages.triage', $b))->assertSessionHas('success');

    Http::assertSentCount(2);
});

test('the global daily cap applies across all visitors', function () {
    config(['triage.rate_limits.ai_per_day_global' => 2]);
    [$a, $b, $c] = newMessages(3);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post(route('messages.triage', $a));
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post(route('messages.triage', $b));
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->post(route('messages.triage', $c))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'daily AI budget'));

    Http::assertSentCount(2);
});

test('the limit resets after a minute', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1]);
    [$a, $b] = newMessages(2);

    $this->post(route('messages.triage', $a))->assertSessionHas('success');
    $this->travel(61)->seconds();
    $this->post(route('messages.triage', $b))->assertSessionHas('success');
});

test('review actions are rate limited too', function () {
    config(['triage.rate_limits.reviews_per_minute_per_ip' => 1]);
    $message = PatientMessage::factory()->create(['status' => MessageStatus::UrgentReview]);

    $this->post(route('messages.edit', $message), ['reviewer' => 'A', 'final_reply' => 'Call 911.'])
        ->assertSessionHas('success');
    $this->post(route('messages.edit', $message), ['reviewer' => 'A', 'final_reply' => 'Again'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many actions'));
});

test('the CLI is not rate limited', function () {
    config(['triage.rate_limits.ai_per_minute_per_ip' => 1, 'triage.rate_limits.ai_per_day_global' => 1]);
    newMessages(3);

    $this->artisan('triage:process')->assertSuccessful();

    Http::assertSentCount(3);
});
