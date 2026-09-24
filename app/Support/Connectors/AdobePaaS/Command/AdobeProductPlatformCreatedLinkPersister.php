<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use Illuminate\Support\Facades\DB;

final class AdobeProductPlatformCreatedLinkPersister
{
    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    public function persistVariantLink(
        string $workspaceId,
        string $connectorAccountId,
        string $productVariantId,
        string $sku,
        int $logicalEntityId,
    ): ExternalRecordLink {
        if (! ctype_digit($productVariantId) || (int) $productVariantId <= 0) {
            throw new AdobeProductPlatformCreatedLinkPersistenceException('platform_created_variant_id_invalid');
        }

        if ($sku === '' || $logicalEntityId <= 0) {
            throw new AdobeProductPlatformCreatedLinkPersistenceException('platform_created_identity_invalid');
        }

        $variantId = (int) $productVariantId;

        return DB::transaction(function () use (
            $workspaceId,
            $connectorAccountId,
            $variantId,
            $sku,
            $logicalEntityId,
        ): ExternalRecordLink {
            ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereKey($connectorAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            $subjectLinks = ExternalRecordLink::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('connector_account_id', $connectorAccountId)
                ->where('product_variant_id', $variantId)
                ->whereNotNull('product_variant_id')
                ->lockForUpdate()
                ->get();

            if ($subjectLinks->count() > 1) {
                throw new AdobeProductPlatformCreatedLinkPersistenceException('platform_created_subject_link_ambiguous');
            }

            $existing = $subjectLinks->first();

            if ($existing instanceof ExternalRecordLink) {
                if (
                    $existing->hasTrustedIdentity()
                    && (string) $existing->external_identifier === $sku
                    && (string) $existing->external_record_discriminator === (string) $logicalEntityId
                ) {
                    return $existing;
                }

                throw new AdobeProductPlatformCreatedLinkPersistenceException('platform_created_subject_link_conflict');
            }

            if ($this->linkGuard->hasCrossSubjectCollision(
                $workspaceId,
                $connectorAccountId,
                $sku,
                (string) $variantId,
            )) {
                throw new AdobeProductPlatformCreatedLinkPersistenceException('platform_created_sku_collision');
            }

            if ($this->linkGuard->hasVariantDiscriminatorCrossSubjectCollision(
                $workspaceId,
                $connectorAccountId,
                (string) $logicalEntityId,
                (string) $variantId,
            )) {
                throw new AdobeProductPlatformCreatedLinkPersistenceException('platform_created_discriminator_collision');
            }

            return ExternalRecordLink::withoutWorkspaceScope()->create([
                'workspace_id' => $workspaceId,
                'connector_account_id' => $connectorAccountId,
                'product_variant_id' => $variantId,
                'external_identifier' => $sku,
                'trust_origin' => ExternalRecordLinkTrustOrigin::PlatformCreated->value,
                'external_record_discriminator' => (string) $logicalEntityId,
                'established_by_workspace_user_id' => null,
                'established_at' => now(),
            ]);
        }, 3);
    }
}
