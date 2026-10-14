<?php

namespace App\Services;

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Jobs\TriageMessage;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Person-initiated triage: queue a not-yet-triaged message, re-run a failed
 * or stale one, or queue everything pending. Each request marks the message
 * "queued", writes a HUMAN audit entry, and dispatches the TriageMessage job.
 */
class TriageQueue
{
    /** Upper bound for one "Triage all pending" click. */
    public const MAX_BULK = 50;

    public function canQueue(PatientMessage $message): bool
    {
        return $message->status === MessageStatus::New;
    }

    /** Failed, or a pending suggestion made by an older prompt or model. */
    public function canRerun(PatientMessage $message): bool
    {
        return $message->status === MessageStatus::AiFailed
            || ($message->status === MessageStatus::PendingReview
                && $message->latestAiAnalysis?->isStale());
    }

    /**
     * @param  string  $source  where the request came from: ui, bulk, cli
     * @param  bool  $rerun  true: only failed/stale results; false: only untriaged messages
     */
    public function queue(PatientMessage $message, string $source = 'ui', bool $rerun = false): void
    {
        DB::transaction(function () use ($message, $source, $rerun) {
            $message->refresh();

            if ($rerun && $this->canRerun($message)) {
                $reason = $message->status === MessageStatus::AiFailed ? 'rerun_failed' : 'rerun_stale';
            } elseif (! $rerun && $this->canQueue($message)) {
                $reason = 'not_triaged';
            } else {
                $what = $rerun ? 'Triage can only be re-run for failed or out-of-date results' : "Triage can't be queued for this message";
                throw new LogicException("{$what} (current state: {$message->status->label()}).");
            }

            $previous = $message->status;
            $message->update(['status' => MessageStatus::Queued]);

            AuditLog::record(AuditActor::Human, 'triage.queued', $message, [
                'reason' => $reason,
                'source' => $source,
                'previous_status' => $previous->value,
            ]);

            // afterCommit (set on the job): dispatched once this transaction commits.
            TriageMessage::dispatch($message);
        });
    }

    /**
     * Queue every message that hasn't been triaged (e.g. the seeded demo data).
     *
     * @return int how many were queued
     */
    public function queuePending(string $source = 'bulk'): int
    {
        $messages = PatientMessage::where('status', MessageStatus::New)
            ->orderBy('received_at')
            ->limit(self::MAX_BULK)
            ->get();

        foreach ($messages as $message) {
            $this->queue($message, $source);
        }

        return $messages->count();
    }
}
