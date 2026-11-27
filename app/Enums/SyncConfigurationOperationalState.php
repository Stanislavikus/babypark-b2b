<?php

namespace App\Enums;

enum SyncConfigurationOperationalState: string
{
    case Enabled = 'enabled';
    case Paused = 'paused';
}
