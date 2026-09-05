<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;
use App\Support\Sync\Live\SyncLiveConsequentialWriteGate;

final readonly class AdobeProductSimpleCommandInput
{
    public function __construct(
        public string $workspaceId,
        public string $connectorAccountId,
        public AdobeProductExportSemanticResult $semanticResult,
        public ?string $adobeBaseCurrency,
        public ?SyncLiveConsequentialWriteGate $consequentialWriteGate = null,
    ) {}
}
