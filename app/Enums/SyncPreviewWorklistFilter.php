<?php

namespace App\Enums;

enum SyncPreviewWorklistFilter: string
{
    case NeedsAttention = 'needs_attention';
    case Blocked = 'blocked';
    case Warning = 'warning';
    case Ready = 'ready';
    case All = 'all';
}
