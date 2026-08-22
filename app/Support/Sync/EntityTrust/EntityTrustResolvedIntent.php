<?php

namespace App\Support\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Models\SyncConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Command\AdobeConfigurableDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Preview\ProductExecutionAggregate;

final readonly class EntityTrustResolvedIntent
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, AdobeProductDesiredState>  $childDesiredStates
     */
    public function __construct(
        public EntityTrustConfirmationMode $mode,
        public SyncConfiguration $configuration,
        public array $snapshot,
        public ProductExecutionAggregate $aggregate,
        public AdobeProductExportExecutionMetadata $metadata,
        public AdobeProductExportSemanticResult $semanticResult,
        public string $localFingerprint,
        public ?AdobeProductDesiredState $simpleDesiredState = null,
        public ?AdobeConfigurableDesiredState $configurableDesiredState = null,
        public array $childDesiredStates = [],
        public ?string $existingParentSkuHint = null,
    ) {}
}
