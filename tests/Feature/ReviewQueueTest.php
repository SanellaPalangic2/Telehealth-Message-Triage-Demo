<?php

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Enums\ReviewAction;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Models\ReviewDecision;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PatientMessageSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

/**
 * A message with a stored AI suggestion, waiting for review.
 */
function pendingMessage(array $analysis = [], string $body = 'Hi, this is Maria Delgado. I need a refill of lisinopril.'): PatientMessage
{
    $message = PatientMessage::factory()->create(['body' => $body, 'status' => MessageStatus::PendingReview]);

    AiAnalysis::create([
        'patient_message_id' => $message->id,
        'redacted_text' => 'Hi, this is [NAME]. I need a refill of lisinopril.',
        'category' => 'refill',
        'urgency' => 'low',
        'summary' => 'Patient requests a lisinopril refill.',
        'draft_reply' => 'Thanks for your message. The care team will review your refill request and follow up.',
        'confidence' => 0.9,
        'requires_review' => false,
        'raw_output' => ['category' => 'refill'],
        'model' => 'claude-opus-5-5',
        'prompt_version' => 'abc123def456',
        'response_id' => 'msg_review_test',
        'latency_ms' => 900,
        'input_tokens' => 700,
        'output_tokens' => 120,
        ...$analysis,
    ]);

    return $message;
}

describe('inbox', function () {
    test('lists messages with status, and urgent ones first', function () {
        $pending = pendingMessage();
        $urgent = PatientMessage::factory()->create(['status' => MessageStatus::UrgentReview, 'body' => 'chest pain']);

        $this->get('/inbox')
            ->assertOk()
            ->assertSeeInOrder(['Urgent – clinician review', 'Pending review'])
            ->assertSee(route('messages.show', $pending))
            ->assertSee(route('messages.show', $urgent));
    });

    test('filters by tab', function () {
        pendingMessage();
        PatientMessage::factory()->create(['status' => MessageStatus::Approved, 'body' => 'An approved one']);

        $this->get('/inbox?tab=done')->assertSee('An approved one')->assertDontSee('lisinopril');
        $this->get('/inbox?tab=pending')->assertSee('lisinopril')->assertDontSee('An approved one');
    });

    test('never shows seeded personal data', function () {
        $this->seed(DatabaseSeeder::class);

        $html = $this->get('/inbox')->assertOk()->getContent();

        foreach (PatientMessageSeeder::data() as $seed) {
            foreach ($seed['pii'] as $pii) {
                expect($html)->not->toContain(e($pii));
            }
        }
    });
});

describe('message detail', function () {
    test('shows the redacted text, the AI suggestion, and the Why panel', function () {
        $message = pendingMessage();

        $this->get(route('messages.show', $message))
            ->assertOk()
            ->assertSee('Hi, this is [NAME]. I need a refill of lisinopril.')
            ->assertDontSee('Maria')
            ->assertSee('Patient requests a lisinopril refill.')
            ->assertSee('The care team will review your refill request')
            ->assertSee('90%')
            ->assertSee('msg_review_test')
            ->assertSee('abc123def456')
            ->assertSee('Approve draft as written');
    });

    test('shows the rules that fired for a red flag', function () {
        $message = PatientMessage::factory()->create(['body' => 'My name is Carla Ruiz and I have crushing chest pain']);
        Http::fake();
        $this->post(route('messages.triage', $message));

        $this->get(route('messages.show', $message))
            ->assertSee('Urgent: clinician review')
            ->assertSee('Chest pain or pressure')
            ->assertSee('chest pain')
            ->assertSee('The AI was not called.')
            ->assertSee('Record clinician reply')
            ->assertDontSee('Carla')
            ->assertDontSee('Approve draft as written');
        Http::assertNothingSent();
    });

    test('shows a clear error and the rejected output when the AI failed', function () {
        fakeClaude(triageInput(['draft_reply' => 'We guarantee approval.']));
        $message = PatientMessage::factory()->create(['body' => 'Refill please']);
        $this->post(route('messages.triage', $message));

        $this->get(route('messages.show', $message))
            ->assertSee('AI triage failed.')
            ->assertSee('The draft reply makes a guarantee.')
            ->assertSee('Rejected AI output')
            ->assertSee('Write reply manually')
            ->assertDontSee('Approve draft as written');
    });

    test('flags low-confidence suggestions', function () {
        $message = pendingMessage(['confidence' => 0.42, 'requires_review' => true]);

        $this->get(route('messages.show', $message))
            ->assertSee('Needs a closer look')
            ->assertSee('low AI confidence (42%)');
    });

    test('renders model text escaped', function () {
        $message = pendingMessage([
            'summary' => '<script>alert("summary")</script>',
            'draft_reply' => '<img src=x onerror=alert(1)>',
            'raw_output' => ['summary' => '<script>alert("raw")</script>'],
        ]);

        $html = $this->get(route('messages.show', $message))->getContent();

        expect($html)->not->toContain('<script>alert')
            ->and($html)->not->toContain('<img src=x')
            ->and($html)->toContain('&lt;script&gt;alert(&quot;summary&quot;)&lt;/script&gt;')
            ->and($html)->toContain('&lt;img src=x onerror=alert(1)&gt;');
    });
});

test('no view uses unescaped Blade output', function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if (str_ends_with($file, '.blade.php')) {
            expect(file_get_contents($file))->not->toContain('{!!', "Unescaped output in {$file}");
        }
    }
});

describe('run triage from the UI', function () {
    test('creates a suggestion for a new message', function () {
        fakeClaude();
        $message = PatientMessage::factory()->create(['body' => 'Can I get a refill?']);

        $this->post(route('messages.triage', $message))
            ->assertRedirect(route('messages.show', $message))
            ->assertSessionHas('success');

        expect($message->fresh()->status)->toBe(MessageStatus::PendingReview);
    });

    test('refuses a message that was already triaged', function () {
        $message = pendingMessage();
        Http::fake();

        $this->post(route('messages.triage', $message))->assertSessionHas('error');
        Http::assertNothingSent();
    });
});

describe('approve', function () {
    test('approves the draft, records the decision, and audits it as HUMAN', function () {
        $message = pendingMessage();

        $this->post(route('messages.approve', $message), ['reviewer' => 'Demo Reviewer'])
            ->assertRedirect(route('messages.show', $message))
            ->assertSessionHas('success');

        $decision = ReviewDecision::sole();
        expect($message->fresh()->status)->toBe(MessageStatus::Approved)
            ->and($decision->action)->toBe(ReviewAction::Approved)
            ->and($decision->final_reply)->toBe($message->latestAiAnalysis->draft_reply)
            ->and($decision->reviewer)->toBe('Demo Reviewer');

        $log = AuditLog::where('action', 'review.approved')->sole();
        expect($log->actor)->toBe(AuditActor::Human)
            ->and($log->details['reviewer'])->toBe('Demo Reviewer')
            ->and($log->details['previous_status'])->toBe('pending_review');
    });

    test('requires a reviewer name', function () {
        $message = pendingMessage();

        $this->post(route('messages.approve', $message), ['reviewer' => ''])->assertSessionHasErrors('reviewer');

        expect($message->fresh()->status)->toBe(MessageStatus::PendingReview)
            ->and(ReviewDecision::count())->toBe(0);
    });

    test('cannot decide the same message twice', function () {
        $message = pendingMessage();

        $this->post(route('messages.approve', $message), ['reviewer' => 'A']);
        $this->post(route('messages.approve', $message), ['reviewer' => 'B'])->assertSessionHas('error');

        expect(ReviewDecision::count())->toBe(1)
            ->and(AuditLog::where('actor', AuditActor::Human)->count())->toBe(1);
    });

    test('is not possible for urgent messages', function () {
        $message = PatientMessage::factory()->create(['status' => MessageStatus::UrgentReview]);

        $this->post(route('messages.approve', $message), ['reviewer' => 'A'])->assertSessionHas('error');

        expect(ReviewDecision::count())->toBe(0);
    });
});

describe('edit', function () {
    test('approves the edited reply and records that it changed', function () {
        $message = pendingMessage();

        $this->post(route('messages.edit', $message), [
            'reviewer' => 'Demo Reviewer',
            'final_reply' => 'Thanks! Your refill request is with the care team.',
            'note' => 'Shortened',
        ])->assertSessionHas('success');

        $decision = ReviewDecision::sole();
        expect($message->fresh()->status)->toBe(MessageStatus::Approved)
            ->and($decision->action)->toBe(ReviewAction::Edited)
            ->and($decision->final_reply)->toBe('Thanks! Your refill request is with the care team.')
            ->and($decision->ai_analysis_id)->toBe($message->latestAiAnalysis->id);

        $log = AuditLog::where('action', 'review.edited')->sole();
        expect($log->actor)->toBe(AuditActor::Human)
            ->and($log->details['reply_changed'])->toBeTrue()
            ->and($log->details['note'])->toBe('Shortened');
    });

    test('requires a reply', function () {
        $message = pendingMessage();

        $this->post(route('messages.edit', $message), ['reviewer' => 'A', 'final_reply' => ''])
            ->assertSessionHasErrors('final_reply');
    });

    test('lets a clinician resolve an urgent message without any AI', function () {
        $message = PatientMessage::factory()->create(['status' => MessageStatus::UrgentReview]);

        $this->post(route('messages.edit', $message), [
            'reviewer' => 'Dr. Demo',
            'final_reply' => 'Please call 911 now.',
        ])->assertSessionHas('success');

        $decision = ReviewDecision::sole();
        expect($decision->ai_analysis_id)->toBeNull()
            ->and($message->fresh()->status)->toBe(MessageStatus::Approved)
            ->and(AuditLog::where('action', 'review.edited')->sole()->details)->not->toHaveKey('reply_changed');
    });
});

describe('reject', function () {
    test('rejects with a reason', function () {
        $message = pendingMessage();

        $this->post(route('messages.reject', $message), ['reviewer' => 'A', 'note' => 'Wrong category'])
            ->assertSessionHas('success');

        expect($message->fresh()->status)->toBe(MessageStatus::Rejected)
            ->and(ReviewDecision::sole()->final_reply)->toBeNull()
            ->and(AuditLog::where('action', 'review.rejected')->sole()->details['note'])->toBe('Wrong category');
    });

    test('requires a reason', function () {
        $message = pendingMessage();

        $this->post(route('messages.reject', $message), ['reviewer' => 'A', 'note' => ''])
            ->assertSessionHasErrors('note');
    });
});

test('the reviewer name is remembered for the next form', function () {
    $message = pendingMessage();
    $this->post(route('messages.approve', $message), ['reviewer' => 'Remembered Name']);

    $this->get(route('messages.show', pendingMessage()))->assertSee('value="Remembered Name"', false);
});

test('a full review keeps an ordered RULE → AI → HUMAN audit trail', function () {
    fakeClaude();
    $message = PatientMessage::factory()->create(['body' => 'Refill please']);

    $this->post(route('messages.triage', $message));
    $this->post(route('messages.approve', $message), ['reviewer' => 'A']);

    expect($message->auditLogs()->orderBy('id')->pluck('action')->all())
        ->toBe(['rules.passed', 'ai.suggested', 'review.approved']);

    $this->get(route('messages.show', $message))
        ->assertSeeInOrder(['rules.passed', 'ai.suggested', 'review.approved']);
});
