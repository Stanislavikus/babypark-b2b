<?php

namespace App\Support\Connectors;

final readonly class ConnectorDiscoveryNormalizedField
{
    public function __construct(
        public CanonicalSchemaField $field,
        public string $canonicalHash,
    ) {}
}
