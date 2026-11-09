<?php

namespace App\Services\Pricing\Resolution;

enum PriceResolutionStepStatus: string
{
    case Matched = 'matched';
    case Skipped = 'skipped';
    case NotChecked = 'not_checked';
    case Failed = 'failed';
}
