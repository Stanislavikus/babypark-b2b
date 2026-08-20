<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;

final class AdobeProductExternalRecordLinkGuard
{
    public function findTrustedLinkForVariant(
        string $workspaceId,
        string $connectorAccountId,
        string $productVariantId,
        string $expectedExternalIdentifier,
    ): ?ExternalRecordLink {
        return ExternalRecordLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('product_variant_id', $productVariantId)
            ->where('external_identifier', $expectedExternalIdentifier)
            ->first();
    }

    public function hasCrossSubjectCollision(
        string $workspaceId,
        string $connectorAccountId,
        string $externalIdentifier,
        string $productVariantId,
    ): bool {
        return ExternalRecordLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('external_identifier', $externalIdentifier)
            ->where('product_variant_id', '!=', $productVariantId)
            ->exists();
    }
}
