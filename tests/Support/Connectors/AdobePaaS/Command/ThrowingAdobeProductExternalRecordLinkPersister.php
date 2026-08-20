<?php

namespace Tests\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductDesiredState;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistence;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkPersistenceException;

final class ThrowingAdobeProductExternalRecordLinkPersister implements AdobeProductExternalRecordLinkPersistence
{
    public function persistTrustedVariantLink(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductDesiredState $desiredState,
    ): ExternalRecordLink {
        throw AdobeProductExternalRecordLinkPersistenceException::collisionDetected();
    }
}
