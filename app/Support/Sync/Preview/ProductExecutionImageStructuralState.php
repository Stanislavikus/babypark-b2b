<?php

namespace App\Support\Sync\Preview;

enum ProductExecutionImageStructuralState: string
{
    case Valid = 'valid';
    case Malformed = 'malformed';
}
