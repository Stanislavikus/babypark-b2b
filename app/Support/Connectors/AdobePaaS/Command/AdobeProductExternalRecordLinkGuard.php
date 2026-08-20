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
        $currentVariantId = (int) $productVariantId;

        return ExternalRecordLink::query()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('external_identifier', $externalIdentifier)
            ->where(function ($query) use ($currentVariantId): void {
                $query->whereNotNull('product_id')
                    ->orWhere(function ($inner) use ($currentVariantId): void {
                        $inner->whereNotNull('product_variant_id')
                            ->where('product_variant_id', '!=', $currentVariantId);
                    });
            })
            ->exists();
    }
}
