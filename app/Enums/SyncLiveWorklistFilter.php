<?php

namespace App\Enums;

enum SyncLiveWorklistFilter: string
{
    case NeedsAttention = 'needs_attention';
    case NotApplied = 'not_applied';
    case Partial = 'partial';
    case Ambiguous = 'ambiguous';
    case Synchronized = 'synchronized';
    case All = 'all';
}
