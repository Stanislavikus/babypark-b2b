<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;

final class AdobeProductExternalRecordLinkGuard
{
    public function resolveTrustedVariantLinkBySubject(
        string $workspaceId,
        string $connectorAccountId,
        string $productVariantId,
    ): AdobeProductTrustedVariantLinkLookup {
        $links = ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('product_variant_id', (int) $productVariantId)
            ->whereNotNull('product_variant_id')
            ->get();

        if ($links->isEmpty()) {
            return AdobeProductTrustedVariantLinkLookup::none();
        }

        if ($links->count() > 1) {
            return AdobeProductTrustedVariantLinkLookup::ambiguous();
        }

        return AdobeProductTrustedVariantLinkLookup::trusted($links->first());
    }

    public function hasCrossSubjectCollision(
        string $workspaceId,
        string $connectorAccountId,
        string $externalIdentifier,
        string $productVariantId,
    ): bool {
        $currentVariantId = (int) $productVariantId;

        return ExternalRecordLink::withoutWorkspaceScope()
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
