<?php

namespace App\Services;

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Services\Rules\RuleHit;
use App\Services\Rules\RulesEngine;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Triage flow for one message:
 *
 *   1. RulesEngine (deterministic). Any red flag → urgent clinician queue,
 *      logged as RULE, and the AI is never called.
 *   2. Otherwise → AI suggestion (added in Phase 4).
 */
class TriagePipeline
{
    public function __construct(private RulesEngine $rules) {}

    public function process(PatientMessage $message): TriageOutcome
    {
        if ($message->status !== MessageStatus::New) {
            throw new LogicException("Message {$message->id} has already been triaged ({$message->status->value}).");
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

        return TriageOutcome::NeedsAi;
    }
}
