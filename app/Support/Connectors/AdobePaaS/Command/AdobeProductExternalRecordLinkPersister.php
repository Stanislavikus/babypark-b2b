<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
        try {
            return DB::transaction(function () use ($workspaceId, $connectorAccountId, $desiredState): ExternalRecordLink {
                ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $connectorAccountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->linkGuard->hasCrossSubjectCollision(
                    $workspaceId,
                    $connectorAccountId,
                    $desiredState->sku,
                    $desiredState->productVariantId,
                )) {
                    throw AdobeProductExternalRecordLinkPersistenceException::collisionDetected();
                }

                $trustedLookup = $this->linkGuard->resolveTrustedVariantLinkBySubject(
                    $workspaceId,
                    $connectorAccountId,
                    $desiredState->productVariantId,
                );

                if ($trustedLookup->isAmbiguous()) {
                    throw AdobeProductExternalRecordLinkPersistenceException::ambiguousVariantIdentity();
                }

                if ($trustedLookup->isTrusted()) {
                    $existingLink = $trustedLookup->link;

                    if ($existingLink->external_identifier !== $desiredState->sku) {
                        throw AdobeProductExternalRecordLinkPersistenceException::identityDriftDetected();
                    }

                    return $existingLink;
                }

                $variant = ProductVariant::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', (int) $desiredState->productVariantId)
                    ->first();

                if ($variant === null) {
                    throw AdobeProductExternalRecordLinkPersistenceException::variantNotFound();
                }

                return ExternalRecordLink::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspaceId,
                    'connector_account_id' => $connectorAccountId,
                    'product_id' => null,
                    'product_variant_id' => (int) $desiredState->productVariantId,
                    'external_identifier' => $desiredState->sku,
                ]);
            });
        } catch (AdobeProductExternalRecordLinkPersistenceException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            throw AdobeProductExternalRecordLinkPersistenceException::connectorAccountNotFound($exception);
        } catch (QueryException $exception) {
            throw AdobeProductExternalRecordLinkPersistenceException::databaseFailure($exception);
        }
    }
}
