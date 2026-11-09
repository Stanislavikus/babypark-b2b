<?php

namespace Tests\Support\Sync;

use App\Support\Sync\Live\ConnectorLiveRuntimeReadiness;
use Closure;
use Illuminate\Support\Facades\DB;

final class TestConnectorLiveRuntimeReadiness implements ConnectorLiveRuntimeReadiness
{
    public bool $ready = true;

    public int $checks = 0;

    public ?Closure $onCheck = null;

    /** @var list<int> */
    public array $transactionLevels = [];

    public function isReady(string $workspaceId, string $connectorAccountId): bool
    {
        $this->checks++;
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->onCheck instanceof Closure) {
            ($this->onCheck)($workspaceId, $connectorAccountId);
        }

        return $this->ready;
    }
}
