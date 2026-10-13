<?php

namespace App\Services\Rules;

final readonly class RulesResult
{
    /**
     * @param  list<RuleHit>  $hits
     */
    public function __construct(public array $hits) {}

    public function isRedFlag(): bool
    {
        return $this->hits !== [];
    }

    /** @return list<string> */
    public function ruleIds(): array
    {
        return array_map(fn (RuleHit $hit) => $hit->rule, $this->hits);
    }
}
