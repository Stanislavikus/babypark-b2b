<?php

namespace App\Support\Connectors\Transport;

final readonly class ValidatedConnectorDestination
{
    public function __construct(
        public ConnectorDestinationKind $kind,
        public string $scheme,
        public string $host,
        public int $port,
        public ?string $pinnedIp,
    ) {}
}
