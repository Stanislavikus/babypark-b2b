<?php

namespace App\Enums;

enum SyncPreviewOutcome: string
{
    case Ready = 'ready';
    case Warning = 'warning';
    case Blocked = 'blocked';
}
