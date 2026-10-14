<?php

namespace App\Models;

use App\Services\TriagePrompt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_message_id', 'redacted_text',
    'category', 'urgency', 'summary', 'draft_reply', 'confidence', 'requires_review', 'raw_output',
    'model', 'prompt_version', 'response_id', 'latency_ms', 'input_tokens', 'output_tokens',
])]
class AiAnalysis extends Model
{
    /**
     * Produced by a different prompt version or model than the current
     * configuration, so it may not reflect how triage behaves now.
     */
    public function isStale(): bool
    {
        return $this->prompt_version !== TriagePrompt::version()
            || $this->model !== config('services.anthropic.model');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'requires_review' => 'boolean',
            'raw_output' => 'array',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    /** @return BelongsTo<PatientMessage, $this> */
    public function patientMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class);
    }
}
