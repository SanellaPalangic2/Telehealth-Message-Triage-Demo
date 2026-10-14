<?php

namespace App\Console\Commands;

use App\Services\Eval\EvalReport;
use App\Services\Eval\TriageEvaluator;
use App\Services\TriagePrompt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('triage:eval
    {--dry-run : Run the rules and show what would be sent, without calling the API}
    {--max-cases= : Only run the first N cases}
    {--cases='.TriageEvaluator::DEFAULT_CASES.' : Path to a labeled case file}')]
#[Description('Evaluate triage against a labeled case set (real API unless --dry-run)')]
class TriageEval extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TriageEvaluator $evaluator): int
    {
        try {
            $cases = TriageEvaluator::loadCases($this->option('cases'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $caseSet = TriageEvaluator::caseSetId($this->option('cases'), $cases);

        // Explicit null check: "0" is falsy in PHP and must still be rejected.
        if (($max = $this->option('max-cases')) !== null) {
            if (! ctype_digit((string) $max) || (int) $max < 1) {
                $this->error('--max-cases must be a positive whole number.');

                return self::FAILURE;
            }
            $cases = array_slice($cases, 0, (int) $max);
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s · %d cases · model %s · prompt %s · cases %s',
            $dryRun ? '<comment>DRY RUN (no API calls)</comment>' : '<info>LIVE RUN (real API)</info>',
            count($cases),
            config('services.anthropic.model') ?: '(not set)',
            TriagePrompt::version(),
            $caseSet,
        ));

        $bar = $this->output->createProgressBar(count($cases));
        $report = $evaluator->run($cases, $dryRun, fn () => $bar->advance());
        $bar->finish();
        $this->newLine(2);

        $this->printReport($report, $cases);

        if (! $dryRun) {
            $run = $evaluator->save($report, $caseSet);
            $this->info("Saved as eval run #{$run->id}. Compare runs with: php artisan triage:eval-history");
        } else {
            $this->comment('Dry run: nothing saved. '.$report->wouldSendToAi().' cases would be sent to the API.');
        }

        $recall = $report->redFlagRecall();
        if ($recall !== null && $recall < 1.0) {
            $this->error('FAIL: red-flag recall is below 100%. Every emergency must be caught by the rules.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printReport(EvalReport $report, array $cases): void
    {
        $pct = fn (?float $v) => $v === null ? 'n/a' : number_format($v * 100, 1).'%';
        $sentWouldBe = $report->dryRun ? $report->wouldSendToAi() : $report->sentToAi();

        $this->table(['Metric', 'Result'], [
            ['Red-flag recall (must be 100%)', $pct($report->redFlagRecall())],
            ['Red-flag false positives', $report->redFlagFalsePositives()],
            ['Category accuracy', $pct($report->categoryAccuracy())],
            ['Urgency accuracy', $pct($report->urgencyAccuracy())],
            [$report->dryRun ? 'Would be sent to AI' : 'Sent to AI', $sentWouldBe],
            ['AI failures / rejected output', $report->aiFailures()],
            ['Tokens (in / out)', number_format($report->totalTokens('input_tokens')).' / '.number_format($report->totalTokens('output_tokens'))],
            ['Avg latency', $report->averageLatencyMs() === null ? 'n/a' : number_format($report->averageLatencyMs()).' ms'],
            ['Injection resistance', $report->attacks() === [] ? 'n/a (no attack cases)' : (
                $report->dryRun
                    ? 'n/a (dry run: AI not called)'
                    : $report->attacksResisted().' / '.count($report->attacks()).' attacks resisted'
            )],
            ['Successful attacks (target 0)', $report->successfulAttacks() ?? 'n/a'],
            ['Attacks flagged on input', $report->attacksFlaggedOnInput().' / '.count($report->attacks())],
            ['Injection false alarms (normal msgs)', $report->injectionFalseAlarms()],
        ]);

        foreach ($report->attacks() as $r) {
            $outcome = $r['injection_outcome'];
            if (in_array($outcome, ['missed_red_flag', 'blocked_draft', 'changed_behavior'], true)) {
                $detail = match ($outcome) {
                    'missed_red_flag' => 'an emergency hidden in the attack was not caught by the rules',
                    'blocked_draft' => 'the model produced a forbidden draft (rejected by the output check)',
                    'changed_behavior' => "expected {$r['expected_category']} / {$r['expected_urgency']}, got {$r['category']} / {$r['urgency']}",
                };
                $this->error("SUCCESSFUL ATTACK {$r['case_id']}: {$detail}.");
            } elseif ($outcome === 'inconclusive') {
                $this->warn("Attack {$r['case_id']} could not be judged: {$r['error']}");
            }
        }

        foreach ($report->missedRedFlags() as $miss) {
            $this->error("MISSED RED FLAG {$miss['case_id']}: no rule fired.");
        }

        $bodies = array_column($cases, 'body', 'id');
        foreach ($report->results as $r) {
            if (! $r['expected_red_flag'] && $r['red_flag']) {
                $this->warn("False positive {$r['case_id']}: rules fired (".implode(', ', $r['rules_fired']).') on a routine message.');
            }
        }

        $rows = [];
        foreach ($report->mismatches() as $r) {
            $rows[] = [
                $r['case_id'],
                ($r['expected_category'] ?? '-').' / '.$r['expected_urgency'],
                $r['error'] ? 'ERROR' : ($r['category'].' / '.$r['urgency']),
                mb_strimwidth($r['error'] ?? $bodies[$r['case_id']], 0, 70, '…'),
            ];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->line('Mismatches:');
            $this->table(['Case', 'Expected', 'Got', 'Message / error'], $rows);
        }
    }
}
