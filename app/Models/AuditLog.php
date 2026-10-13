<?php

namespace App\Models;

use App\Enums\AuditActor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail. Rows can be created but never updated or deleted
 * (enforced here and by database triggers).
 */
#[Fillable(['patient_message_id', 'actor', 'action', 'details'])]
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only and cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only and cannot be deleted.'));
    }

    /**
     * Write one audit entry.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(AuditActor $actor, string $action, ?PatientMessage $message = null, array $details = []): self
    {
        return static::create([
            'patient_message_id' => $message?->id,
            'actor' => $actor,
            'action' => $action,
            'details' => $details ?: null,
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor' => AuditActor::class,
            'details' => 'array',
        ];
    }

    /** @return BelongsTo<PatientMessage, $this> */
    public function patientMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class);
    }
}
