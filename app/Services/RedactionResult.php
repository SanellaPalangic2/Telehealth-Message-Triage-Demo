<?php

namespace App\Services;

/**
 * The output of RedactionService: the safe text plus how many of each kind
 * of personal data were removed (counts only, never the values).
 */
final readonly class RedactionResult
{
    /**
     * @param  array<string, int>  $counts  placeholder => number replaced, e.g. ['PHONE' => 1]
     */
    public function __construct(
        public string $text,
        public array $counts,
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
