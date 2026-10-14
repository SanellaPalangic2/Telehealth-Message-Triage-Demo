<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'eval_run_id', 'case_id', 'expected_red_flag', 'red_flag', 'rules_fired',
    'expected_category', 'category', 'expected_urgency', 'urgency', 'confidence',
    'error', 'latency_ms', 'input_tokens', 'output_tokens', 'response_id',
    'attack', 'injection_detected', 'suspected_injection', 'injection_outcome',
])]
class EvalResult extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_red_flag' => 'boolean',
            'red_flag' => 'boolean',
            'attack' => 'boolean',
            'injection_detected' => 'boolean',
            'suspected_injection' => 'boolean',
            'rules_fired' => 'array',
            'confidence' => 'float',
        ];
    }

    /** @return BelongsTo<EvalRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'eval_run_id');
    }
}
