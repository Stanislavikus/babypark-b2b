<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ExternalRecordLink;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class AdobeProductExternalRecordLinkPersister implements AdobeProductExternalRecordLinkPersistence
{
    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
    ) {}

    /**
     * @throws AdobeProductExternalRecordLinkPersistenceException
     */
    public function persistTrustedVariantLink(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductDesiredState $desiredState,
    ): ExternalRecordLink {
        return DB::transaction(function () use ($workspaceId, $connectorAccountId, $desiredState): ExternalRecordLink {
            if ($this->linkGuard->hasCrossSubjectCollision(
                $workspaceId,
                $connectorAccountId,
                $desiredState->sku,
                $desiredState->productVariantId,
            )) {
                throw AdobeProductExternalRecordLinkPersistenceException::collisionDetected();
            }

            $variant = ProductVariant::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', (int) $desiredState->productVariantId)
                ->first();

            if ($variant === null) {
                throw AdobeProductExternalRecordLinkPersistenceException::variantNotFound();
            }

            $existing = $this->linkGuard->findTrustedLinkForVariant(
                $workspaceId,
                $connectorAccountId,
                $desiredState->productVariantId,
                $desiredState->sku,
            );

            if ($existing !== null) {
                return $existing;
            }

            return ExternalRecordLink::query()->create([
                'workspace_id' => $workspaceId,
                'connector_account_id' => $connectorAccountId,
                'product_id' => null,
                'product_variant_id' => (int) $desiredState->productVariantId,
                'external_identifier' => $desiredState->sku,
            ]);
        });
    }
}
