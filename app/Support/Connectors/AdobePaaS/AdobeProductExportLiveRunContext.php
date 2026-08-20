<?php

namespace App\Support\Connectors\AdobePaaS;

final readonly class AdobeProductExportLiveRunContext
{
    public function __construct(
        public string $workspaceId,
        public string $connectorAccountId,
        public AdobeProductExportExecutionMetadata $metadata,
        public string $adobeBaseCurrency,
    ) {}
}
