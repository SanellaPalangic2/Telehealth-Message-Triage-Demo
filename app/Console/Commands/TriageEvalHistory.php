<?php

namespace App\Console\Commands;

use App\Models\EvalRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('triage:eval-history {--limit=10 : How many recent runs to show}')]
#[Description('Compare saved eval runs by model and prompt version')]
class TriageEvalHistory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $runs = EvalRun::latest('id')->limit((int) $this->option('limit'))->get();

        if ($runs->isEmpty()) {
            $this->info('No eval runs yet. Run: php artisan triage:eval');

            return self::SUCCESS;
        }

        $pct = fn (?float $v) => $v === null ? 'n/a' : number_format($v * 100, 1).'%';

        $this->table(
            ['Run', 'When', 'Model', 'Prompt', 'Cases', 'Category', 'Urgency', 'Red-flag recall', 'FP', 'AI fails', 'Attacks won', 'Tokens in/out'],
            $runs->map(fn (EvalRun $run) => [
                "#{$run->id}",
                $run->started_at->format('Y-m-d H:i'),
                $run->model,
                $run->prompt_version,
                $run->cases_total,
                $pct($run->category_accuracy),
                $pct($run->urgency_accuracy),
                $pct($run->red_flag_recall),
                $run->red_flag_false_positives,
                $run->ai_failures,
                // Successful attacks out of total attack cases (target 0). "-" before the metric existed.
                $run->injection_attacks > 0 ? "{$run->injection_successes} / {$run->injection_attacks}" : '-',
                number_format($run->input_tokens).' / '.number_format($run->output_tokens),
            ]),
        );

        return self::SUCCESS;
    }
}
