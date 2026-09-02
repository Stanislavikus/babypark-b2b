<?php

namespace App\Support\Sync\Live;

use App\Models\ConnectorAccount;

interface ConnectorLiveRuntimeReadiness
{
    public function isReady(ConnectorAccount $account): bool;
}
