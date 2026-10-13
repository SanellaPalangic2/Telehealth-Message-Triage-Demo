<?php

namespace App\Enums;

/**
 * What a human reviewer did with an AI suggestion.
 */
enum ReviewAction: string
{
    case Approved = 'approved';
    case Edited = 'edited';
    case Rejected = 'rejected';
}
