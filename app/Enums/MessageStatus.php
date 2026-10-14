<?php

namespace App\Enums;

enum MessageStatus: string
{
    /** Received, not triaged yet. */
    case New = 'new';

    /** A person asked for (re-)triage; waiting for the queue worker. */
    case Queued = 'queued';

    /** A red-flag rule fired. Skips the AI and goes to a clinician. */
    case UrgentReview = 'urgent_review';

    /** The AI produced a suggestion that is waiting for a human. */
    case PendingReview = 'pending_review';

    /** The AI call failed or returned invalid output. A human must handle it. */
    case AiFailed = 'ai_failed';

    /** A human approved the reply (as drafted or edited). */
    case Approved = 'approved';

    /** A human rejected the AI suggestion. */
    case Rejected = 'rejected';

    /** Statuses the triage pipeline will run on. */
    public function isTriageable(): bool
    {
        return $this === self::New || $this === self::Queued;
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Queued => 'Queued for triage',
            self::UrgentReview => 'Urgent – clinician review',
            self::PendingReview => 'Pending review',
            self::AiFailed => 'AI failed – manual triage',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
