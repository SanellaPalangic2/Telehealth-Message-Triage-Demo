<?php

namespace App\Services\Eval;

use App\Models\EvalRun;
use App\Services\Ai\AiTriageException;
use App\Services\Ai\AiTriageService;
use App\Services\Rules\RulesEngine;
use App\Services\TriagePrompt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs a labeled case set through the same path production uses:
 * rules first, and only cases the rules don't flag go to the AI.
 *
 * Writes nothing to the patient tables. Real runs are saved to
 * eval_runs / eval_results; dry runs make no API calls and aren't saved.
 */
class TriageEvaluator
{
    public const DEFAULT_CASES = 'database/eval/triage_cases.php';

    public function __construct(
        private RulesEngine $rules,
        private AiTriageService $ai,
    ) {}

    /**
     * @return list<array{id: string, red_flag: bool, category: ?string, urgency: string, body: string}>
     */
    public static function loadCases(string $path): array
    {
        $full = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : base_path($path);

        if (! is_file($full)) {
            throw new InvalidArgumentException("Case file not found: {$path}");
        }

        return require $full;
    }

    /**
     * Identifies the exact case set: file name + content hash.
     */
    public static function caseSetId(string $path, array $cases): string
    {
        return pathinfo($path, PATHINFO_FILENAME).'@'.substr(hash('sha256', json_encode($cases)), 0, 8);
    }

    /**
     * @param  list<array{id: string, red_flag: bool, category: ?string, urgency: string, body: string}>  $cases
     * @param  callable(array, array): void|null  $onCase  called after each case with (case, result)
     */
    public function run(array $cases, bool $dryRun, ?callable $onCase = null): EvalReport
    {
        $startedAt = now();
        $results = [];

        foreach ($cases as $case) {
            $rules = $this->rules->evaluate($case['body']);

            $result = [
                'case_id' => $case['id'],
                'expected_red_flag' => $case['red_flag'],
                'red_flag' => $rules->isRedFlag(),
                'rules_fired' => $rules->ruleIds(),
                'expected_category' => $case['category'],
                'category' => null,
                'expected_urgency' => $case['urgency'],
                'urgency' => null,
                'confidence' => null,
                'error' => null,
                'latency_ms' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'response_id' => null,
                'sent_to_ai' => false,
            ];

            // Same as production: a rule hit means the AI is never called.
            if (! $rules->isRedFlag() && ! $dryRun) {
                $result['sent_to_ai'] = true;

                try {
                    $ai = $this->ai->analyze($case['body']);
                    $result = [
                        ...$result,
                        'category' => $ai->output['category'],
                        'urgency' => $ai->output['urgency'],
                        'confidence' => $ai->output['confidence'],
                        'latency_ms' => $ai->latencyMs,
                        'input_tokens' => $ai->inputTokens,
                        'output_tokens' => $ai->outputTokens,
                        'response_id' => $ai->responseId,
                    ];
                } catch (AiTriageException $e) {
                    $result['error'] = $e->getMessage();
                }
            }

            $results[] = $result;
            $onCase && $onCase($case, $result);
        }

        return new EvalReport($results, $dryRun, $startedAt, now());
    }

    public function save(EvalReport $report, string $caseSet): EvalRun
    {
        return DB::transaction(function () use ($report, $caseSet) {
            $run = EvalRun::create([
                'model' => config('services.anthropic.model'),
                'prompt_version' => TriagePrompt::version(),
                'case_set' => $caseSet,
                'cases_total' => count($report->results),
                'cases_sent_to_ai' => $report->sentToAi(),
                'category_accuracy' => $report->categoryAccuracy(),
                'urgency_accuracy' => $report->urgencyAccuracy(),
                'red_flag_recall' => $report->redFlagRecall(),
                'red_flag_false_positives' => $report->redFlagFalsePositives(),
                'ai_failures' => $report->aiFailures(),
                'input_tokens' => $report->totalTokens('input_tokens'),
                'output_tokens' => $report->totalTokens('output_tokens'),
                'avg_latency_ms' => $report->averageLatencyMs(),
                'started_at' => $report->startedAt,
                'finished_at' => $report->finishedAt,
            ]);

            foreach ($report->results as $result) {
                $run->results()->create(collect($result)->except('sent_to_ai')->all());
            }

            return $run;
        });
    }
}
