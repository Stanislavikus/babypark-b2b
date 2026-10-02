<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorSchemaFieldDisposition;

final readonly class ConnectorSchemaFieldClassificationDecision
{
    /** @param array<string, mixed>|null $behaviorSignature */
    public function __construct(
        public ConnectorSchemaFieldDisposition $disposition,
        public ?string $behaviorClass,
        public ?array $behaviorSignature,
        public ?string $runtimeOwnerHint,
        public ?string $canonicalCode,
        public string $mappingStrategy,
        public string $classifierVersion,
        public string $reasonCode,
    ) {}
}
