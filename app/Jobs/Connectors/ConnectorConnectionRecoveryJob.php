<?php

namespace App\Jobs\Connectors;

use App\Services\Connectors\ConnectorConnectionCheckDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ConnectorConnectionRecoveryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 30;

    public int $maxExceptions = 1;

    public int $uniqueFor = 1209600;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $connectorAccountId,
        private readonly string $sourceCheckId,
    ) {
        $this->onConnection('database_connectors');
        $this->onQueue('connectors');
    }

    public function handle(ConnectorConnectionCheckDispatchService $dispatchService): void
    {
        $dispatchService->executeRecovery(
            $this->workspaceId,
            $this->connectorAccountId,
            $this->sourceCheckId,
        );
    }

    public function uniqueId(): string
    {
        return $this->sourceCheckId;
    }
}
