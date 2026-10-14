<?php

namespace App\Enums;

enum Urgency: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
