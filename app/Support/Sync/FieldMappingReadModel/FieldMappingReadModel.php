<?php

namespace App\Support\Sync\FieldMappingReadModel;

final readonly class FieldMappingReadModel
{
    /**
     * @param  list<FieldMappingInternalRow>  $internalRows
     * @param  list<DiscoveredExternalFieldChoice>  $discoveredExternalChoices
     */
    public function __construct(
        public string $syncConfigurationId,
        public bool $discoveryAvailable,
        public array $internalRows,
        public array $discoveredExternalChoices,
    ) {}
}
