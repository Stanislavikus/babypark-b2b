<?php

namespace App\Support\Connectors;

final readonly class ConnectionCheckDispatchDecision
{
    public function __construct(
        public string $connectionCheckId,
        public bool $shouldDispatch,
        public ?int $retryUntilTimestamp,
    ) {}
}
