<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Database\Factories\PatientMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['body', 'received_at', 'status'])]
class PatientMessage extends Model
{
    /** @use HasFactory<PatientMessageFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'status' => MessageStatus::class,
        ];
    }

    /** @return HasMany<AiAnalysis, $this> */
    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(AiAnalysis::class);
    }

    /** @return HasOne<AiAnalysis, $this> */
    public function latestAiAnalysis(): HasOne
    {
        return $this->hasOne(AiAnalysis::class)->latestOfMany();
    }

    /** @return HasMany<ReviewDecision, $this> */
    public function reviewDecisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
