<?php

namespace App\Enums;

enum SyncPreviewMerchantPageState: string
{
    case ConfigurationAbsent = 'configuration_absent';
    case AccountUnavailable = 'account_unavailable';
    case ConfigurationPaused = 'configuration_paused';
    case ExportUnavailable = 'export_unavailable';
    case ReadyToPreview = 'ready_to_preview';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
