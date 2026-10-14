<?php

namespace App\Services\Eval;

use Carbon\CarbonInterface;

/**
 * Results of one eval run plus the metrics computed from them.
 *
 * Scoring rules:
 * - Red-flag recall = expected red flags caught by the rules / expected red flags.
 * - Category and urgency accuracy are scored only on routine cases the AI
 *   actually saw. A failed or rejected AI call counts as wrong: there is no
 *   fallback answer, so "no answer" is not "right".
 * - Routine cases the rules over-flagged never reach the AI; they're counted
 *   as red-flag false positives, not scored for category/urgency.
 */
final readonly class EvalReport
{
    /**
     * @param  list<array<string, mixed>>  $results
     */
    public function __construct(
        public array $results,
        public bool $dryRun,
        public CarbonInterface $startedAt,
        public CarbonInterface $finishedAt,
    ) {}

    public function redFlagRecall(): ?float
    {
        $expected = $this->where(fn ($r) => $r['expected_red_flag']);

        return $expected === [] ? null : count(array_filter($expected, fn ($r) => $r['red_flag'])) / count($expected);
    }

    /** @return list<array<string, mixed>> */
    public function missedRedFlags(): array
    {
        return $this->where(fn ($r) => $r['expected_red_flag'] && ! $r['red_flag']);
    }

    public function redFlagFalsePositives(): int
    {
        return count($this->where(fn ($r) => ! $r['expected_red_flag'] && $r['red_flag']));
    }

    public function categoryAccuracy(): ?float
    {
        return $this->accuracy('category');
    }

    public function urgencyAccuracy(): ?float
    {
        return $this->accuracy('urgency');
    }

    public function sentToAi(): int
    {
        return count($this->scored(includeMissedRedFlags: true));
    }

    /** Cases the rules didn't flag, i.e. what a live run sends to the AI. */
    public function wouldSendToAi(): int
    {
        return count($this->where(fn ($r) => ! $r['red_flag']));
    }

    public function aiFailures(): int
    {
        return count($this->where(fn ($r) => $r['error'] !== null));
    }

    public function totalTokens(string $field): int
    {
        return (int) array_sum(array_column($this->results, $field));
    }

    public function averageLatencyMs(): ?int
    {
        $latencies = array_filter(array_column($this->results, 'latency_ms'), fn ($v) => $v !== null);

        return $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies));
    }

    /** @return list<array<string, mixed>> */
    public function mismatches(): array
    {
        return $this->where(fn ($r) => in_array($r, $this->scored(), true)
            && ($r['category'] !== $r['expected_category'] || $r['urgency'] !== $r['expected_urgency']));
    }

    private function accuracy(string $field): ?float
    {
        $scored = $this->scored();

        if ($this->dryRun || $scored === []) {
            return null;
        }

        return count(array_filter($scored, fn ($r) => $r[$field] === $r["expected_{$field}"])) / count($scored);
    }

    /**
     * Routine cases that went to the AI (optionally plus red flags the
     * rules missed, which production would also have sent to the AI).
     *
     * @return list<array<string, mixed>>
     */
    private function scored(bool $includeMissedRedFlags = false): array
    {
        return $this->where(fn ($r) => $r['sent_to_ai']
            && ($includeMissedRedFlags || ! $r['expected_red_flag']));
    }

    /** @return list<array<string, mixed>> */
    private function where(callable $filter): array
    {
        return array_values(array_filter($this->results, $filter));
    }
}
