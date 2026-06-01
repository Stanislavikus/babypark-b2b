<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
