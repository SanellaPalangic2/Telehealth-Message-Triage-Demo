<?php

namespace App\Services;

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Enums\ReviewAction;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Models\ReviewDecision;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Human review decisions. This is the only way a message reaches a final
 * state: the AI suggests, a person decides.
 *
 * Each decision, its status change, and its HUMAN audit entry are written in
 * one transaction. The status is re-checked inside the transaction so a
 * double-submitted form can't decide the same message twice.
 */
class ReviewService
{
    /** Approve the AI draft exactly as written. */
    public function approve(PatientMessage $message, string $reviewer): ReviewDecision
    {
        return $this->decide($message, [MessageStatus::PendingReview], function (PatientMessage $message) use ($reviewer) {
            $analysis = $message->latestAiAnalysis
                ?? throw new LogicException('There is no AI suggestion to approve.');

            return [ReviewAction::Approved, MessageStatus::Approved, [
                'ai_analysis_id' => $analysis->id,
                'final_reply' => $analysis->draft_reply,
                'reviewer' => $reviewer,
            ]];
        });
    }

    /**
     * Approve a reply written or edited by the reviewer. Also how a person
     * resolves messages the AI never handled (urgent or AI-failed).
     */
    public function edit(PatientMessage $message, string $reviewer, string $reply, ?string $note = null): ReviewDecision
    {
        $allowed = [MessageStatus::PendingReview, MessageStatus::UrgentReview, MessageStatus::AiFailed];

        return $this->decide($message, $allowed, function (PatientMessage $message) use ($reviewer, $reply, $note) {
            $analysis = $message->status === MessageStatus::PendingReview ? $message->latestAiAnalysis : null;

            return [ReviewAction::Edited, MessageStatus::Approved, [
                'ai_analysis_id' => $analysis?->id,
                'final_reply' => trim($reply),
                'reviewer' => $reviewer,
                'note' => $note,
            ]];
        });
    }

    /** Reject the AI suggestion. */
    public function reject(PatientMessage $message, string $reviewer, string $note): ReviewDecision
    {
        return $this->decide($message, [MessageStatus::PendingReview], function (PatientMessage $message) use ($reviewer, $note) {
            return [ReviewAction::Rejected, MessageStatus::Rejected, [
                'ai_analysis_id' => $message->latestAiAnalysis?->id,
                'final_reply' => null,
                'reviewer' => $reviewer,
                'note' => $note,
            ]];
        });
    }

    /**
     * @param  list<MessageStatus>  $allowed
     * @param  callable(PatientMessage): array{0: ReviewAction, 1: MessageStatus, 2: array<string, mixed>}  $build
     */
    private function decide(PatientMessage $message, array $allowed, callable $build): ReviewDecision
    {
        return DB::transaction(function () use ($message, $allowed, $build) {
            $message->refresh();
            $previous = $message->status;

            if (! in_array($previous, $allowed, true)) {
                throw new LogicException("This message can't be reviewed in its current state ({$previous->label()}).");
            }

            [$action, $newStatus, $attributes] = $build($message);

            $decision = $message->reviewDecisions()->create(['action' => $action, ...$attributes]);
            $message->update(['status' => $newStatus]);

            $draft = $message->latestAiAnalysis?->draft_reply;

            AuditLog::record(AuditActor::Human, "review.{$action->value}", $message, array_filter([
                'review_decision_id' => $decision->id,
                'reviewer' => $decision->reviewer,
                'ai_analysis_id' => $decision->ai_analysis_id,
                'previous_status' => $previous->value,
                'status' => $newStatus->value,
                'reply_changed' => $action === ReviewAction::Edited && $draft !== null
                    ? $decision->final_reply !== $draft
                    : null,
                'note' => $decision->note,
            ], fn ($value) => $value !== null));

            return $decision;
        });
    }
}
