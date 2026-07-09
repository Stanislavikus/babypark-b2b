<?php

namespace App\Enums;

enum PriceListItemStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
