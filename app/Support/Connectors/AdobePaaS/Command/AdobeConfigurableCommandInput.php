<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;

final readonly class AdobeConfigurableCommandInput
{
    public function __construct(
        public string $workspaceId,
        public string $connectorAccountId,
        public AdobeProductExportSemanticResult $semanticResult,
        public AdobeConfigurableDesiredState $desiredState,
        public ?string $adobeBaseCurrency,
        public ?AdobeProductExportExecutionMetadata $metadata,
        public ?SyncLiveConsequentialWriteGate $consequentialWriteGate = null,
    ) {}
}
