<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticResult;

final readonly class AdobeProductSimpleCommandInput
{
    /**
     * @param  list<array<string, mixed>>  $fieldMappings
     */
    public function __construct(
        public string $workspaceId,
        public string $connectorAccountId,
        public AdobeProductExportSemanticResult $semanticResult,
        public ?string $adobeBaseCurrency,
        public array $fieldMappings = [],
    ) {}
}
