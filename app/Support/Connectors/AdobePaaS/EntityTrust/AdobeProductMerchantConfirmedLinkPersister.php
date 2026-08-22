<?php

namespace App\Support\Connectors\AdobePaaS\EntityTrust;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WorkspaceUser;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;

/**
 * Dedicated explicit merchant-confirmation writer.
 * Does not weaken the automatic Live execution persister boundary.
 */
final class AdobeProductMerchantConfirmedLinkPersister
{
    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    public function establishVariantLink(
        string $workspaceId,
        string $connectorAccountId,
        ProductVariant $variant,
        string $externalSku,
        string $discriminator,
        WorkspaceUser $actor,
        bool $allowLegacyUpgrade,
        bool $allowRelink,
    ): ExternalRecordLink {
        return $this->establishLink(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            productId: null,
            variant: $variant,
            externalSku: $externalSku,
            discriminator: $discriminator,
            actor: $actor,
            allowLegacyUpgrade: $allowLegacyUpgrade,
            allowRelink: $allowRelink,
            isParent: false,
        );
    }

    public function establishParentLink(
        string $workspaceId,
        string $connectorAccountId,
        Product $product,
        string $externalSku,
        string $discriminator,
        WorkspaceUser $actor,
        bool $allowLegacyUpgrade,
        bool $allowRelink,
    ): ExternalRecordLink {
        return $this->establishLink(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            productId: $product->id,
            variant: null,
            externalSku: $externalSku,
            discriminator: $discriminator,
            actor: $actor,
            allowLegacyUpgrade: $allowLegacyUpgrade,
            allowRelink: $allowRelink,
            isParent: true,
        );
    }

    private function establishLink(
        string $workspaceId,
        string $connectorAccountId,
        ?int $productId,
        ?ProductVariant $variant,
        string $externalSku,
        string $discriminator,
        WorkspaceUser $actor,
        bool $allowLegacyUpgrade,
        bool $allowRelink,
        bool $isParent,
    ): ExternalRecordLink {
        if ($isParent) {
            if ($this->linkGuard->hasParentSkuCrossSubjectCollision($workspaceId, $connectorAccountId, $externalSku, (int) $productId)) {
                throw EntityTrustException::linkCollision();
            }

            if ($this->linkGuard->hasParentDiscriminatorCrossSubjectCollision($workspaceId, $connectorAccountId, $discriminator, (int) $productId)) {
                throw EntityTrustException::linkCollision();
            }

            $lookup = $this->linkGuard->resolveTrustedParentLinkBySubject($workspaceId, $connectorAccountId, (int) $productId);
            $subjectRows = ExternalRecordLink::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('connector_account_id', $connectorAccountId)
                ->where('product_id', $productId)
                ->get();
        } else {
            $variantId = (string) $variant->id;

            if ($this->linkGuard->hasCrossSubjectCollision($workspaceId, $connectorAccountId, $externalSku, $variantId)) {
                throw EntityTrustException::linkCollision();
            }

            if ($this->linkGuard->hasVariantDiscriminatorCrossSubjectCollision($workspaceId, $connectorAccountId, $discriminator, $variantId)) {
                throw EntityTrustException::linkCollision();
            }

            $lookup = $this->linkGuard->resolveTrustedVariantLinkBySubject($workspaceId, $connectorAccountId, $variantId);
            $subjectRows = ExternalRecordLink::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('connector_account_id', $connectorAccountId)
                ->where('product_variant_id', $variant->id)
                ->get();
        }

        if ($lookup->isAmbiguous() || $subjectRows->count() > 1) {
            throw EntityTrustException::ambiguousExistingLink();
        }

        if ($lookup->isTrusted()) {
            $existing = $lookup->link;

            if ($existing->external_record_discriminator === $discriminator) {
                if ($existing->external_identifier === $externalSku) {
                    return $existing;
                }

                return $this->upgradeExistingRow($existing, $externalSku, $discriminator, $actor);
            }

            if (! $allowRelink) {
                throw EntityTrustException::differentEntityWithoutRelink();
            }

            return $this->upgradeExistingRow($existing, $externalSku, $discriminator, $actor);
        }

        if ($subjectRows->count() === 1) {
            $legacy = $subjectRows->first();

            if ($legacy === null || $legacy->hasMerchantConfirmedTrust()) {
                throw EntityTrustException::ambiguousExistingLink();
            }

            if (! $allowLegacyUpgrade) {
                throw EntityTrustException::ambiguousExistingLink();
            }

            if (! $this->isLegacyRowCompatibleForUpgrade($legacy, $externalSku, $discriminator)) {
                throw EntityTrustException::ambiguousExistingLink();
            }

            return $this->upgradeExistingRow($legacy, $externalSku, $discriminator, $actor);
        }

        if ($subjectRows->count() > 0) {
            throw EntityTrustException::ambiguousExistingLink();
        }

        return ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'product_id' => $isParent ? $productId : null,
            'product_variant_id' => $isParent ? null : $variant->id,
            'external_identifier' => $externalSku,
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
            'external_record_discriminator' => $discriminator,
            'established_by_workspace_user_id' => $actor->id,
            'established_at' => now(),
        ]);
    }

    private function isLegacyRowCompatibleForUpgrade(
        ExternalRecordLink $legacy,
        string $externalSku,
        string $discriminator,
    ): bool {
        $existingSku = $legacy->external_identifier;
        $existingDiscriminator = $legacy->external_record_discriminator;

        if (is_string($existingSku) && $existingSku !== '' && $existingSku !== $externalSku) {
            return false;
        }

        if (is_string($existingDiscriminator) && $existingDiscriminator !== '' && $existingDiscriminator !== $discriminator) {
            return false;
        }

        return true;
    }

    private function upgradeExistingRow(
        ExternalRecordLink $link,
        string $externalSku,
        string $discriminator,
        WorkspaceUser $actor,
    ): ExternalRecordLink {
        $link->external_identifier = $externalSku;
        $link->trust_origin = ExternalRecordLinkTrustOrigin::MerchantConfirmed->value;
        $link->external_record_discriminator = $discriminator;
        $link->established_by_workspace_user_id = $actor->id;
        $link->established_at = now();
        $link->save();

        return $link->refresh();
    }
}
