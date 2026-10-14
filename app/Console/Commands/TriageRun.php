<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use App\Services\TriageOutcome;
use App\Services\TriagePipeline;
use App\Services\TriageQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

#[Signature('triage:run
    {--pending : Triage every message that has not been triaged yet (new or queued)}
    {--id=* : Triage these message ids}
    {--queue : Dispatch jobs for the queue worker instead of running now}')]
#[Description('Triage messages now, or queue them for the worker (real API calls)')]
class TriageRun extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TriagePipeline $pipeline, TriageQueue $queue): int
    {
        if (! $this->option('pending') && ! $this->option('id')) {
            $this->error('Choose what to triage: --pending, or --id=<id> (repeatable).');

            return self::FAILURE;
        }

        $messages = PatientMessage::query()
            ->when(
                $this->option('id'),
                fn ($q, $ids) => $q->whereIn('id', $ids),
                fn ($q) => $q->whereIn('status', [MessageStatus::New, MessageStatus::Queued]),
            )
            ->orderBy('received_at')
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No matching messages to triage.');

            return self::SUCCESS;
        }

        return $this->option('queue')
            ? $this->dispatchJobs($messages, $queue)
            : $this->runNow($messages, $pipeline);
    }

    private function dispatchJobs($messages, TriageQueue $queue): int
    {
        $queued = 0;
        foreach ($messages as $message) {
            try {
                // Untriaged messages are queued; failed or stale ones are re-run.
                $queue->queue($message, 'cli', rerun: $queue->canRerun($message));
                $queued++;
            } catch (LogicException $e) {
                $this->warn("#{$message->id} skipped: {$e->getMessage()}");
            }
        }

        $this->info("Queued {$queued} for the worker. Run: php artisan queue:work");

        return self::SUCCESS;
    }

    /**
     * Runs the pipeline in this process (no worker needed). Not counted
     * against the demo's daily AI budget: the CLI is for the owner.
     */
    private function runNow($messages, TriagePipeline $pipeline): int
    {
        $tally = [];

        foreach ($messages as $message) {
            if (! $message->status->isTriageable()) {
                $this->warn("#{$message->id} skipped: already triaged ({$message->status->label()}).");

                continue;
            }

            $outcome = $pipeline->process($message);
            $tally[$outcome->value] = ($tally[$outcome->value] ?? 0) + 1;

            match ($outcome) {
                TriageOutcome::Urgent => $this->warn("#{$message->id} -> URGENT (clinician review)"),
                TriageOutcome::AiFailed => $this->error("#{$message->id} -> AI FAILED: ".$message->auditLogs()->latest('id')->value('details->error')),
                TriageOutcome::Suggested => $this->line("#{$message->id} -> suggestion ready for review"),
            };
        }

        if ($tally !== []) {
            $this->newLine();
            $this->table(['Outcome', 'Messages'], collect($tally)->map(fn ($n, $k) => [$k, $n])->values());
        }

        return self::SUCCESS;
    }
}
