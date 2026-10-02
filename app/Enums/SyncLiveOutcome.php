<?php

namespace App\Enums;

enum SyncLiveOutcome: string
{
    case Synchronized = 'synchronized';
    case NotApplied = 'not_applied';
    case Partial = 'partial';
    case Ambiguous = 'ambiguous';
}
