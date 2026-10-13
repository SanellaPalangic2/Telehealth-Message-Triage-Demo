<?php

namespace App\Services;

enum TriageOutcome: string
{
    /** A red-flag rule fired; sent to the clinician queue without AI. */
    case Urgent = 'urgent';

    /** No red flag; the message should go to the AI for a suggestion. */
    case NeedsAi = 'needs_ai';
}
