<?php

namespace App\Services;

enum TriageOutcome: string
{
    /** A red-flag rule fired; sent to the clinician queue without AI. */
    case Urgent = 'urgent';

    /** The AI produced a valid suggestion that now awaits human review. */
    case Suggested = 'suggested';

    /** The AI call failed or its output was rejected; a human must triage. */
    case AiFailed = 'ai_failed';
}
