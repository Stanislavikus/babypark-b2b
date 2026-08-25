<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\ProductVariant;

final readonly class AdobeStage3EValidationResolvedSubject
{
    public function __construct(
        public ConnectorAccount $account,
        public ProductVariant $variant,
        public ExternalRecordLink $trustedLink,
        public string $workspaceId,
        public string $normalizedHost,
        public string $storeCode,
        public string $sku,
        public int $logicalEntityId,
    ) {}
}
