<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Observers\PatientMessageObserver;
use Database\Factories\PatientMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable(['body', 'received_at', 'status'])]
#[ObservedBy(PatientMessageObserver::class)]
class PatientMessage extends Model
{
    /** @use HasFactory<PatientMessageFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * Status guard, enforced in code rather than trusted to the prompt:
     * only allowed transitions, and approve/reject only when a human review
     * decision exists. (A database trigger enforces the second rule too.)
     */
    protected static function booted(): void
    {
        static::updating(function (PatientMessage $message) {
            if (! $message->isDirty('status')) {
                return;
            }

            $from = $message->getOriginal('status');
            $from = $from instanceof MessageStatus ? $from : MessageStatus::from($from);
            $to = $message->status;

            if (! $from->canTransitionTo($to)) {
                throw new LogicException("A message can't move from {$from->value} to {$to->value}.");
            }

            if ($to->isHumanDecision() && ! $message->reviewDecisions()->exists()) {
                throw new LogicException('Only a human review decision can approve or reject a message.');
            }
        });
    }

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
