<?php

namespace App\Enums;

enum SyncLiveMerchantLifecycleState: string
{
    case None = 'none';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
