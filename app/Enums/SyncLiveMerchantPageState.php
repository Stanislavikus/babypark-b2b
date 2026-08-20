<?php

namespace App\Enums;

enum SyncLiveMerchantPageState: string
{
    case AccountUnavailable = 'account_unavailable';
    case ConfigurationAbsent = 'configuration_absent';
    case ConfigurationPaused = 'configuration_paused';
    case ExportUnavailable = 'export_unavailable';
    case SupportNotEnabled = 'support_not_enabled';
    case ConfigurationNotReady = 'configuration_not_ready';
    case PreviewPrerequisiteMissing = 'preview_prerequisite_missing';
    case ActiveRunBlocking = 'active_run_blocking';
    case ReadyToTransfer = 'ready_to_transfer';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
