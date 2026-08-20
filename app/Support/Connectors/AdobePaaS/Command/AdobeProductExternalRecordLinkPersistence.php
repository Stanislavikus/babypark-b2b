<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;

interface AdobeProductExternalRecordLinkPersistence
{
    /**
     * @throws AdobeProductExternalRecordLinkPersistenceException
     */
    public function persistTrustedVariantLink(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductDesiredState $desiredState,
    ): ExternalRecordLink;
}
