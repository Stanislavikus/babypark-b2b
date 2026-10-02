<?php

namespace App\Support\Sync\AdobeProductExportSetup;

use App\Support\Connectors\ConnectorAccountLayerBSetupProjection;

final readonly class AdobeProductExportSetupReadModel
{
    /**
     * @param  list<array{attribute_set_id: int, attribute_set_name: string}>  $availableAttributeSets
     */
    public function __construct(
        public ConnectorAccountLayerBSetupProjection $account,
        public array $availableAttributeSets,
        public ?int $configuredAttributeSetId,
        public ?string $configuredAttributeSetName,
        public bool $configuredSetStale,
        public bool $setupRequired,
        public ?int $preselectedAttributeSetId,
        public bool $exportEnabled = false,
        public bool $configurationPaused = false,
    ) {}
}
