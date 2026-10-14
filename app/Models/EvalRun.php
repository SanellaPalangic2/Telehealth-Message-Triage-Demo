<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'model', 'prompt_version', 'case_set', 'cases_total', 'cases_sent_to_ai',
    'category_accuracy', 'urgency_accuracy', 'red_flag_recall', 'red_flag_false_positives',
    'ai_failures', 'input_tokens', 'output_tokens', 'avg_latency_ms', 'started_at', 'finished_at',
])]
class EvalRun extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_accuracy' => 'float',
            'urgency_accuracy' => 'float',
            'red_flag_recall' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<EvalResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(EvalResult::class);
    }
}
