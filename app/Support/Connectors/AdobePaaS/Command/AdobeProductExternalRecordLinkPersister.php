<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

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
        } catch (PDOException $exception) {
            throw AdobeProductExternalRecordLinkPersistenceException::databaseFailure($exception);
        }
    }

    /**
     * @throws AdobeProductExternalRecordLinkPersistenceException
     */
    public function persistTrustedParentLink(
        string $workspaceId,
        string $connectorAccountId,
        AdobeProductParentDesiredState $desiredState,
    ): ExternalRecordLink {
        try {
            return DB::transaction(function () use ($workspaceId, $connectorAccountId, $desiredState): ExternalRecordLink {
                ConnectorAccount::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $connectorAccountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->linkGuard->hasParentSkuCrossSubjectCollision(
                    $workspaceId,
                    $connectorAccountId,
                    $desiredState->sku,
                    $desiredState->productId,
                )) {
                    throw AdobeProductExternalRecordLinkPersistenceException::collisionDetected();
                }

                $trustedLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
                    $workspaceId,
                    $connectorAccountId,
                    $desiredState->productId,
                );

                if ($trustedLookup->isAmbiguous()) {
                    throw AdobeProductExternalRecordLinkPersistenceException::ambiguousParentIdentity();
                }

                if ($trustedLookup->isTrusted()) {
                    $existingLink = $trustedLookup->link;

                    if ($existingLink->external_identifier !== $desiredState->sku) {
                        throw AdobeProductExternalRecordLinkPersistenceException::identityDriftDetected();
                    }

                    return $existingLink;
                }

                $product = Product::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $desiredState->productId)
                    ->first();

                if ($product === null) {
                    throw AdobeProductExternalRecordLinkPersistenceException::productNotFound();
                }

                return ExternalRecordLink::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspaceId,
                    'connector_account_id' => $connectorAccountId,
                    'product_id' => $desiredState->productId,
                    'product_variant_id' => null,
                    'external_identifier' => $desiredState->sku,
                ]);
            });
        } catch (AdobeProductExternalRecordLinkPersistenceException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            throw AdobeProductExternalRecordLinkPersistenceException::connectorAccountNotFound($exception);
        } catch (QueryException $exception) {
            throw AdobeProductExternalRecordLinkPersistenceException::databaseFailure($exception);
        } catch (PDOException $exception) {
            throw AdobeProductExternalRecordLinkPersistenceException::databaseFailure($exception);
        }
    }
}
