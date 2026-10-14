<?php

use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\EvalResult;
use App\Models\EvalRun;
use App\Models\PatientMessage;
use App\Services\TriagePrompt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

function evalCaseFile(array $cases): string
{
    $path = storage_path('framework/testing/eval_cases_'.uniqid().'.php');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, '<?php return '.var_export($cases, true).';');

    return $path;
}

$routine = fn (string $id, string $category, string $urgency, string $body) => compact('id', 'category', 'urgency', 'body') + ['red_flag' => false];
$redFlag = fn (string $id, string $body) => ['id' => $id, 'red_flag' => true, 'category' => null, 'urgency' => 'high', 'body' => $body];

test('--dry-run makes no API calls, saves nothing, and reports 100% recall', function () {
    Http::fake();

    $this->artisan('triage:eval --dry-run')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('100.0%')
        ->expectsOutputToContain('30 cases would be sent to the API')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(EvalRun::count())->toBe(0);
});

test('a live run scores category and urgency on routine cases only', function () use ($routine, $redFlag) {
    // The fake AI always answers refill / low.
    fakeClaude(triageInput(['category' => 'refill', 'urgency' => 'low']));

    $file = evalCaseFile([
        $routine('T1', 'refill', 'low', 'Refill my metformin please'),            // both right
        $routine('T2', 'refill', 'medium', 'I ran out of lisinopril'),           // category right
        $routine('T3', 'billing', 'low', 'Question about my bill'),              // urgency right
        $routine('T4', 'appointment', 'medium', 'Reschedule my visit'),         // both wrong
        $redFlag('T5', 'I have crushing chest pain'),                           // caught, no AI
    ]);

    $this->artisan('triage:eval', ['--cases' => $file])->assertSuccessful();

    $run = EvalRun::sole();
    expect($run->cases_total)->toBe(5)
        ->and($run->cases_sent_to_ai)->toBe(4)
        ->and($run->category_accuracy)->toBe(0.5)
        ->and($run->urgency_accuracy)->toBe(0.5)
        ->and($run->red_flag_recall)->toBe(1.0)
        ->and($run->model)->toBe('claude-opus-5-5')
        ->and($run->prompt_version)->toBe(TriagePrompt::version())
        ->and($run->case_set)->toStartWith('eval_cases_')
        ->and($run->input_tokens)->toBe(4 * 812)
        ->and($run->output_tokens)->toBe(4 * 143)
        ->and($run->results)->toHaveCount(5);

    $t5 = EvalResult::where('case_id', 'T5')->sole();
    expect($t5->red_flag)->toBeTrue()
        ->and($t5->rules_fired)->toBe(['chest_pain'])
        ->and($t5->category)->toBeNull();

    Http::assertSentCount(4);
    Http::assertNotSent(fn ($request) => str_contains($request->body(), 'crushing chest pain'));
});

test('a failed AI call counts as wrong, never as a guess', function () use ($routine) {
    fakeClaude(triageInput(['category' => 'not-a-category']));

    $file = evalCaseFile([$routine('T1', 'refill', 'low', 'Refill please')]);

    $this->artisan('triage:eval', ['--cases' => $file])->assertSuccessful();

    $run = EvalRun::sole();
    expect($run->ai_failures)->toBe(1)
        ->and($run->category_accuracy)->toBe(0.0)
        ->and($run->urgency_accuracy)->toBe(0.0)
        ->and(EvalResult::sole()->error)->toContain('failed validation');
});

test('a missed red flag fails the run', function () use ($routine, $redFlag) {
    fakeClaude();

    $file = evalCaseFile([
        $redFlag('T1', 'My heart feels like an elephant is sitting on it'),
        $routine('T2', 'refill', 'low', 'Refill please'),
    ]);

    $this->artisan('triage:eval', ['--cases' => $file])
        ->expectsOutputToContain('MISSED RED FLAG T1')
        ->expectsOutputToContain('red-flag recall is below 100%')
        ->assertFailed();

    expect(EvalRun::sole()->red_flag_recall)->toBe(0.0);
});

test('over-flagged routine messages are reported as false positives', function () use ($routine) {
    Http::fake();

    $file = evalCaseFile([$routine('T1', 'side_effect', 'medium', 'No chest pain, just achy legs')]);

    $this->artisan('triage:eval', ['--cases' => $file, '--dry-run' => true])
        ->expectsOutputToContain('False positive T1')
        ->assertSuccessful();
});

test('--max-cases limits the run', function () {
    fakeClaude();

    $this->artisan('triage:eval --max-cases=8')->assertSuccessful();

    // The first 6 cases are red flags (no AI), then 2 routine cases.
    expect(EvalRun::sole()->cases_total)->toBe(8);
    Http::assertSentCount(2);
});

test('--max-cases must be a positive number', function () {
    $this->artisan('triage:eval --max-cases=abc')->assertFailed();
    $this->artisan('triage:eval --max-cases=0')->assertFailed();
});

test('a missing case file is a clear error', function () {
    $this->artisan('triage:eval --cases=nope.php')
        ->expectsOutputToContain('Case file not found')
        ->assertFailed();
});

test('the eval never touches patient data tables', function () {
    fakeClaude();

    $this->artisan('triage:eval')->assertSuccessful();

    expect(PatientMessage::count())->toBe(0)
        ->and(AiAnalysis::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('triage:eval-history compares saved runs', function () {
    $this->artisan('triage:eval-history')->expectsOutputToContain('No eval runs yet');

    fakeClaude();
    $this->artisan('triage:eval --max-cases=8');
    $this->artisan('triage:eval --max-cases=8');

    Artisan::call('triage:eval-history');
    $output = Artisan::output();

    expect($output)->toContain('#1')
        ->and($output)->toContain('#2')
        ->and($output)->toContain(TriagePrompt::version())
        ->and($output)->toContain('claude-opus-5-5');
});

test('--max-cases=0 is rejected before any API call', function () {
    Http::fake();

    $this->artisan('triage:eval --max-cases=0')->assertFailed();

    Http::assertNothingSent();
});
