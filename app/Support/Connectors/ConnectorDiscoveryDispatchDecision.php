<?php

namespace App\Support\Connectors;

final readonly class ConnectorDiscoveryDispatchDecision
{
    private function __construct(
        public string $discoveryRunId,
        public bool $shouldDispatch,
        public ?int $retryUntilTimestamp,
    ) {}

    public static function dispatch(
        string $discoveryRunId,
        int $retryUntilTimestamp,
    ): self {
        return new self($discoveryRunId, true, $retryUntilTimestamp);
    }

    public static function existing(string $discoveryRunId): self
    {
        return new self($discoveryRunId, false, null);
    }
}
