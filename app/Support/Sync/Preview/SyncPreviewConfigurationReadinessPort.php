<?php

namespace App\Support\Sync\Preview;

use App\Models\SyncConfiguration;

interface SyncPreviewConfigurationReadinessPort
{
    public function isReady(SyncConfiguration $configuration): bool;
}
