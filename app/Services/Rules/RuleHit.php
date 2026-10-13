<?php

namespace App\Services\Rules;

final readonly class RuleHit
{
    public function __construct(
        public string $rule,
        public string $label,
        public string $matched,
    ) {}

    /** @return array{rule: string, label: string, matched: string} */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'label' => $this->label, 'matched' => $this->matched];
    }
}
