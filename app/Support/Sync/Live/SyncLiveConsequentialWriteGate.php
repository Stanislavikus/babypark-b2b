<?php

namespace App\Support\Sync\Live;

interface SyncLiveConsequentialWriteGate
{
    public function permitsConsequentialWrite(): bool;

    public function permitsProductExecution(): bool;
}
