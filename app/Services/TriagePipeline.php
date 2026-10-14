<?php

namespace App\Services;

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Models\AiAnalysis;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiTriageException;
use App\Services\Ai\AiTriageService;
use App\Services\Rules\InjectionDetector;
use App\Services\Rules\RuleHit;
use App\Services\Rules\RulesEngine;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Triage flow for one message:
 *
 *   1. RulesEngine (deterministic). Any red flag → urgent clinician queue,
 *      logged as RULE, and the AI is never called.
 *   2. Otherwise → AiTriageService. A valid result is stored as a
 *      SUGGESTION awaiting human review; a failure is recorded as an error.
 *      Either way nothing is sent to the patient here.
 *
 * Usually run by the TriageMessage queued job; the CLI can also run it
 * directly. Queued runs pass enforceBudget so AI calls count against the
 * daily budget. The rules always run, budget or not.
 */
class TriagePipeline
{
    public function __construct(
        private RulesEngine $rules,
        private InjectionDetector $injectionDetector,
        private AiTriageService $ai,
        private AiBudget $budget,
    ) {}

    public function process(PatientMessage $message, bool $enforceBudget = false): TriageOutcome
    {
        if (! $message->status->isTriageable()) {
            throw new LogicException("Message {$message->id} has already been triaged ({$message->status->value}).");
        }

        // Instruction-like text aimed at the AI. Never blocks the message;
        // it's logged and forces a human to look closely at the suggestion.
        $injection = $this->injectionDetector->evaluate($message->body);
        if ($injection->isRedFlag()) {
            AuditLog::record(AuditActor::Rule, 'injection_attempt', $message, [
                'hits' => array_map(fn (RuleHit $hit) => $hit->toArray(), $injection->hits),
                'effect' => 'suggestion requires human review',
            ]);
        }

        $result = $this->rules->evaluate($message->body);

        if ($result->isRedFlag()) {
            DB::transaction(function () use ($message, $result) {
                $message->update(['status' => MessageStatus::UrgentReview]);

                AuditLog::record(AuditActor::Rule, 'rules.red_flag', $message, [
                    'hits' => array_map(fn (RuleHit $hit) => $hit->toArray(), $result->hits),
                    'status' => MessageStatus::UrgentReview->value,
                    'ai_called' => false,
                ]);
            });

            return TriageOutcome::Urgent;
        }

        AuditLog::record(AuditActor::Rule, 'rules.passed', $message, [
            'rules_checked' => array_keys(RulesEngine::rules()),
            'hits' => [],
        ]);

        try {
            if ($enforceBudget && ! $this->budget->consume()) {
                throw new AiTriageException(
                    AiTriageException::BUDGET_EXHAUSTED,
                    "Today's AI budget for this demo is used up. Red-flag rules still ran; re-run AI triage tomorrow.",
                );
            }

            $suggestion = $this->ai->analyze($message->body);
        } catch (AiTriageException $e) {
            DB::transaction(function () use ($message, $e) {
                $message->update(['status' => MessageStatus::AiFailed]);

                AuditLog::record(AuditActor::Ai, 'ai.failed', $message, [
                    'reason' => $e->reason,
                    'error' => $e->getMessage(),
                    ...$e->details,
                ]);

                // The output check caught the model promising, approving, or
                // leaking something: likely a prompt injection that worked.
                if ($e->details['suspected_injection'] ?? false) {
                    AuditLog::record(AuditActor::Rule, 'suspected_injection', $message, [
                        'reasons' => $e->details['injection_reasons'],
                        'action' => 'AI output rejected; no suggestion created',
                    ]);
                }
            });

            return TriageOutcome::AiFailed;
        }

        // The AI's output only ever becomes a stored SUGGESTION. The status is
        // decided here, in code, and is always pending_review: nothing the AI
        // returns can approve, reject, or skip human review (and the model and
        // a database trigger refuse approval without a human decision).
        DB::transaction(function () use ($message, $suggestion, $injection) {
            $analysis = AiAnalysis::create([
                'patient_message_id' => $message->id,
                'redacted_text' => $suggestion->redaction->text,
                ...$suggestion->output,
                'requires_review' => $suggestion->requiresReview() || $injection->isRedFlag(),
                'raw_output' => $suggestion->rawOutput,
                'model' => $suggestion->model,
                'prompt_version' => $suggestion->promptVersion,
                'response_id' => $suggestion->responseId,
                'latency_ms' => $suggestion->latencyMs,
                'input_tokens' => $suggestion->inputTokens,
                'output_tokens' => $suggestion->outputTokens,
            ]);

            $message->update(['status' => MessageStatus::PendingReview]);

            AuditLog::record(AuditActor::Ai, 'ai.suggested', $message, [
                'ai_analysis_id' => $analysis->id,
                'category' => $analysis->category,
                'urgency' => $analysis->urgency,
                'confidence' => $analysis->confidence,
                'requires_review' => $analysis->requires_review,
                'redactions' => $suggestion->redaction->counts,
                'model' => $analysis->model,
                'prompt_version' => $analysis->prompt_version,
                'response_id' => $analysis->response_id,
            ]);
        });

        return TriageOutcome::Suggested;
    }
}
