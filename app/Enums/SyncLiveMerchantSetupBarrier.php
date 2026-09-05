<?php

namespace App\Enums;

enum SyncLiveMerchantSetupBarrier: string
{
    case AccountUnavailable = 'account_unavailable';
    case ConfigurationAbsent = 'configuration_absent';
    case ConfigurationPaused = 'configuration_paused';
    case ExportUnavailable = 'export_unavailable';
    case ConfigurationNotReady = 'configuration_not_ready';
}
