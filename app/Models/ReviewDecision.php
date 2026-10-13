<?php

namespace App\Models;

use App\Enums\ReviewAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['patient_message_id', 'ai_analysis_id', 'action', 'final_reply', 'reviewer', 'note'])]
class ReviewDecision extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ReviewAction::class,
        ];
    }

    /** @return BelongsTo<PatientMessage, $this> */
    public function patientMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class);
    }

    /** @return BelongsTo<AiAnalysis, $this> */
    public function aiAnalysis(): BelongsTo
    {
        return $this->belongsTo(AiAnalysis::class);
    }
}
