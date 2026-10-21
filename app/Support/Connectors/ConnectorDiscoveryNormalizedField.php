<?php

namespace App\Support\Connectors;

final readonly class ConnectorDiscoveryNormalizedField
{
    public function __construct(
        #[\SensitiveParameter] public CanonicalSchemaField $field,
        #[\SensitiveParameter] public string $canonicalHash,
    ) {}
}
