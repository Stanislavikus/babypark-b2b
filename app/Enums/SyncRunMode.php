<?php

namespace App\Enums;

enum SyncRunMode: string
{
    case Preview = 'preview';
    case Live = 'live';
}
