<?php

namespace App\Services\Pricing\Inspection;

enum PriceInspectorTone: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Critical = 'critical';
}
