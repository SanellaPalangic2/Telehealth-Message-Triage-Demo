<?php

namespace App\Jobs;

use App\Enums\AuditActor;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\PatientMessage;
use App\Services\TriagePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the full triage pipeline for one message in the background:
 * red-flag rules → redaction → AI classification → validation, with every
 * step written to the audit log by the pipeline.
 *
 * Dispatched automatically when a message is created (PatientMessageObserver),
 * and when a person queues or re-runs triage. Queued AI calls count against
 * the daily AI budget.
 */
class TriageMessage implements ShouldQueue
{
    use Queueable;

    /** AI errors are handled inside the pipeline; don't retry and double-bill. */
    public int $tries = 1;

    /** One AI call (60s timeout, with retries inside the service) plus headroom. */
    public int $timeout = 180;

    /** If the message was deleted before the job ran, just drop the job. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public PatientMessage $message)
    {
        // Don't dispatch until the message is actually committed.
        $this->afterCommit();
    }

    /**
     * Duplicate jobs for one message are harmless (handle() re-checks the
     * status), but two workers must never triage the same message at the
     * same time, or the AI would be called twice. Not ShouldBeUnique: that
     * locks at dispatch time, so a job waiting with no worker running would
     * block "Queue triage" for the lock's lifetime.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->message->id))->dontRelease()->expireAfter(300)];
    }

    public function handle(TriagePipeline $pipeline): void
    {
        $message = $this->message->fresh();

        // Already handled (e.g. triaged from the CLI while this job waited).
        if ($message === null || ! $message->status->isTriageable()) {
            return;
        }

        $pipeline->process($message, enforceBudget: true);
    }

    /**
     * Unexpected crash (not an AI error, which the pipeline already handles).
     * Mark the message as failed so a person picks it up. No fake output.
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () {
            $message = $this->message->fresh();

            if ($message === null || ! $message->status->isTriageable()) {
                return;
            }

            $message->update(['status' => MessageStatus::AiFailed]);

            AuditLog::record(AuditActor::Ai, 'ai.failed', $message, [
                'reason' => 'job_failed',
                'error' => 'Triage could not finish because of an internal error. No suggestion was created.',
            ]);
        });
    }
}
