<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use App\Services\TriageOutcome;
use App\Services\TriagePipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('triage:process {--id=* : Only process these message ids}')]
#[Description('Run new patient messages through the triage pipeline')]
class TriageProcess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TriagePipeline $pipeline): int
    {
        $messages = PatientMessage::query()
            ->where('status', MessageStatus::New)
            ->when($this->option('id'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('received_at')
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No new messages to process.');

            return self::SUCCESS;
        }

        $tally = [];
        foreach ($messages as $message) {
            $outcome = $pipeline->process($message);
            $tally[$outcome->value] = ($tally[$outcome->value] ?? 0) + 1;

            match ($outcome) {
                TriageOutcome::Urgent => $this->warn("#{$message->id} -> URGENT (clinician review)"),
                TriageOutcome::AiFailed => $this->error("#{$message->id} -> AI FAILED: ".$message->auditLogs()->latest('id')->value('details->error')),
                TriageOutcome::Suggested => $this->line("#{$message->id} -> suggestion ready for review"),
            };
        }

        $this->newLine();
        $this->table(['Outcome', 'Messages'], collect($tally)->map(fn ($n, $k) => [$k, $n])->values());

        return self::SUCCESS;
    }
}
