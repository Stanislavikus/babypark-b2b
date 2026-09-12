<?php

namespace App\Support\Connectors;

final readonly class ConnectorDiscoveryField
{
    public function __construct(
        #[\SensitiveParameter] public ConnectorDiscoveryIdentifiedField $field,
        #[\SensitiveParameter] public string $canonicalHash,
    ) {}
}
