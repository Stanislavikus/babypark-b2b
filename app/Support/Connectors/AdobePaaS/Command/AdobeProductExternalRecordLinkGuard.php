<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;
use Illuminate\Support\Collection;

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

        return $this->resolveTrustedLinkLookup($links);
    }

    public function resolveTrustedParentLinkBySubject(
        string $workspaceId,
        string $connectorAccountId,
        int $productId,
    ): AdobeProductTrustedParentLinkLookup {
        $links = ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('product_id', $productId)
            ->whereNotNull('product_id')
            ->get();

        return $this->resolveTrustedParentLinkLookup($links);
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

    public function hasVariantDiscriminatorCrossSubjectCollision(
        string $workspaceId,
        string $connectorAccountId,
        string $externalRecordDiscriminator,
        string $productVariantId,
    ): bool {
        if ($externalRecordDiscriminator === '') {
            return false;
        }

        $currentVariantId = (int) $productVariantId;

        return ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('external_record_discriminator', $externalRecordDiscriminator)
            ->where(function ($query) use ($currentVariantId): void {
                $query->whereNotNull('product_id')
                    ->orWhere(function ($inner) use ($currentVariantId): void {
                        $inner->whereNotNull('product_variant_id')
                            ->where('product_variant_id', '!=', $currentVariantId);
                    });
            })
            ->exists();
    }

    public function hasParentSkuCrossSubjectCollision(
        string $workspaceId,
        string $connectorAccountId,
        string $parentSku,
        int $productId,
    ): bool {
        return ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('external_identifier', $parentSku)
            ->where(function ($query) use ($productId): void {
                $query->where(function ($inner) use ($productId): void {
                    $inner->whereNotNull('product_id')
                        ->where('product_id', '!=', $productId);
                })->orWhereNotNull('product_variant_id');
            })
            ->exists();
    }

    public function hasParentDiscriminatorCrossSubjectCollision(
        string $workspaceId,
        string $connectorAccountId,
        string $externalRecordDiscriminator,
        int $productId,
    ): bool {
        if ($externalRecordDiscriminator === '') {
            return false;
        }

        return ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $connectorAccountId)
            ->where('external_record_discriminator', $externalRecordDiscriminator)
            ->where(function ($query) use ($productId): void {
                $query->where(function ($inner) use ($productId): void {
                    $inner->whereNotNull('product_id')
                        ->where('product_id', '!=', $productId);
                })->orWhereNotNull('product_variant_id');
            })
            ->exists();
    }

    /**
     * @param  Collection<int, ExternalRecordLink>  $links
     */
    private function resolveTrustedLinkLookup($links): AdobeProductTrustedVariantLinkLookup
    {
        if ($links->isEmpty()) {
            return AdobeProductTrustedVariantLinkLookup::none();
        }

        if ($links->count() > 1) {
            return AdobeProductTrustedVariantLinkLookup::ambiguous();
        }

        $link = $links->first();

        if ($link !== null && $link->hasMerchantConfirmedTrust()) {
            return AdobeProductTrustedVariantLinkLookup::trusted($link);
        }

        return AdobeProductTrustedVariantLinkLookup::none();
    }

    /**
     * @param  Collection<int, ExternalRecordLink>  $links
     */
    private function resolveTrustedParentLinkLookup($links): AdobeProductTrustedParentLinkLookup
    {
        if ($links->isEmpty()) {
            return AdobeProductTrustedParentLinkLookup::none();
        }

        if ($links->count() > 1) {
            return AdobeProductTrustedParentLinkLookup::ambiguous();
        }

        $link = $links->first();

        if ($link !== null && $link->hasMerchantConfirmedTrust()) {
            return AdobeProductTrustedParentLinkLookup::trusted($link);
        }

        return AdobeProductTrustedParentLinkLookup::none();
    }
}
