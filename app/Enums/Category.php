<?php

namespace App\Enums;

enum Category: string
{
    case Refill = 'refill';
    case SideEffect = 'side_effect';
    case Appointment = 'appointment';
    case Billing = 'billing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Refill => 'Refill',
            self::SideEffect => 'Side effect',
            self::Appointment => 'Appointment',
            self::Billing => 'Billing',
            self::Other => 'Other',
        };
    }
}
