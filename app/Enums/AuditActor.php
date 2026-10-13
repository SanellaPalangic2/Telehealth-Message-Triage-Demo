<?php

namespace App\Enums;

/**
 * Who performed an audited action.
 */
enum AuditActor: string
{
    case Ai = 'AI';
    case Rule = 'RULE';
    case Human = 'HUMAN';
}
