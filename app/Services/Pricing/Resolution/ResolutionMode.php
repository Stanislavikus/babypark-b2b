<?php

namespace App\Services\Pricing\Resolution;

enum ResolutionMode: string
{
    case Standard = 'standard';
    case Diagnostic = 'diagnostic';
}
