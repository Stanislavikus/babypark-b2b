<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSAccessRejectionEvidence;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\Transport\ConnectorHttpResult;

final class AdobeProductStockSimpleWriteExecutor
{
    public function __construct(
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateComparator $comparator,
    ) {}

    public function execute(
        string $workspaceId,
        string $connectorAccountId,
        int $trustedEntityId,
        AdobeProductDesiredState $desiredState,
    ): AdobeProductSimpleCommandResult {
        $context = $this->contextFactory->create($workspaceId, $connectorAccountId);
        $preRead = $this->remoteStateClient->getProductWithContext($context, $desiredState->sku);

        if ($preRead->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->knownNotApplied(
                'linked_remote_product_missing',
                $desiredState->sku,
                $preRead->classification,
            );
        }

        $observed = $preRead->observedState;

        if ($preRead->classification !== AdobeProductRemoteGetClassification::Found || $observed === null) {
            return $this->unknownOrAmbiguous(
                'stock_pre_read_untrusted_or_failed',
                $desiredState->sku,
                $preRead->classification,
            );
        }

        if ($observed->entityId !== $trustedEntityId) {
            return $this->knownNotApplied(
                'identity_mismatch',
                $desiredState->sku,
                $preRead->classification,
            );
        }

        if ($desiredState->typeId !== 'simple' || $observed->typeId !== 'simple') {
            return $this->knownNotApplied(
                'remote_product_type_mismatch',
                $desiredState->sku,
                $preRead->classification,
            );
        }

        if ($this->comparator->controlledStateMatches($desiredState, $observed)) {
            return $this->knownApplied(
                'stock_state_already_matches',
                $desiredState->sku,
                $preRead->classification,
            );
        }

        [$writeHttp, $writeTransportException] = $this->remoteStateClient->putProduct(
            $context,
            $desiredState,
        );

        if ($writeTransportException !== null || $writeHttp === null) {
            return $this->reconcileAfterAttempt(
                $context,
                $trustedEntityId,
                $desiredState,
                'stock_write_transport_ambiguous',
            );
        }

        $accessClassification = $this->writeAccessClassification($writeHttp);

        if ($accessClassification === AdobeProductWriteAccessClassification::PermissionDenied) {
            return $this->knownNotApplied(
                'stock_write_permission_denied',
                $desiredState->sku,
                $preRead->classification,
                consequentialWriteAttempts: 1,
                writeAccessClassification: $accessClassification,
            );
        }

        if ($writeHttp->statusCode >= 200 && $writeHttp->statusCode < 300) {
            return $this->reconcileAfterAttempt(
                $context,
                $trustedEntityId,
                $desiredState,
                'stock_write_postcondition_unverified',
                writeAccessClassification: $accessClassification,
            );
        }

        return $this->reconcileAfterAttempt(
            $context,
            $trustedEntityId,
            $desiredState,
            'stock_write_http_rejected_or_failed',
            writeAccessClassification: $accessClassification,
        );
    }

    private function reconcileAfterAttempt(
        AdobePaaSRequestContext $context,
        int $trustedEntityId,
        AdobeProductDesiredState $desiredState,
        string $fallbackReasonCode,
        ?AdobeProductWriteAccessClassification $writeAccessClassification = null,
    ): AdobeProductSimpleCommandResult {
        $reconciled = $this->remoteStateClient->getProductWithContext($context, $desiredState->sku);
        $observed = $reconciled->observedState;

        if ($reconciled->classification === AdobeProductRemoteGetClassification::Found && $observed !== null) {
            if ($observed->entityId !== $trustedEntityId || $observed->typeId !== 'simple') {
                return $this->unknownOrAmbiguous(
                    'stock_post_write_identity_mismatch',
                    $desiredState->sku,
                    $reconciled->classification,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                    writeAccessClassification: $writeAccessClassification,
                );
            }

            if ($this->comparator->controlledStateMatches($desiredState, $observed)) {
                return $this->knownApplied(
                    'stock_write_verified',
                    $desiredState->sku,
                    $reconciled->classification,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                    writeAccessClassification: $writeAccessClassification,
                );
            }
        }

        return $this->unknownOrAmbiguous(
            $fallbackReasonCode,
            $desiredState->sku,
            $reconciled->classification,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
            writeAccessClassification: $writeAccessClassification,
        );
    }

    private function writeAccessClassification(ConnectorHttpResult $httpResult): ?AdobeProductWriteAccessClassification
    {
        if (! in_array($httpResult->statusCode, [401, 403], true)) {
            return null;
        }

        if (AdobePaaSAccessRejectionEvidence::hasStructuredResourceDenial($httpResult->body)) {
            return AdobeProductWriteAccessClassification::PermissionDenied;
        }

        return AdobeProductWriteAccessClassification::AccessRejectedUndetermined;
    }

    private function knownApplied(
        string $reasonCode,
        string $subjectSku,
        ?AdobeProductRemoteGetClassification $remoteGetClassification,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        ?AdobeProductWriteAccessClassification $writeAccessClassification = null,
    ): AdobeProductSimpleCommandResult {
        return $this->result(
            AdobeProductAppliedStateKnowledge::KnownApplied,
            $reasonCode,
            $subjectSku,
            $remoteGetClassification,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
            $writeAccessClassification,
        );
    }

    private function knownNotApplied(
        string $reasonCode,
        string $subjectSku,
        ?AdobeProductRemoteGetClassification $remoteGetClassification,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        ?AdobeProductWriteAccessClassification $writeAccessClassification = null,
    ): AdobeProductSimpleCommandResult {
        return $this->result(
            AdobeProductAppliedStateKnowledge::KnownNotApplied,
            $reasonCode,
            $subjectSku,
            $remoteGetClassification,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
            $writeAccessClassification,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        string $subjectSku,
        ?AdobeProductRemoteGetClassification $remoteGetClassification,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        ?AdobeProductWriteAccessClassification $writeAccessClassification = null,
    ): AdobeProductSimpleCommandResult {
        return $this->result(
            AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            $reasonCode,
            $subjectSku,
            $remoteGetClassification,
            $consequentialWriteAttempts,
            $reconciliationGetAttempts,
            $writeAccessClassification,
        );
    }

    private function result(
        AdobeProductAppliedStateKnowledge $appliedStateKnowledge,
        string $reasonCode,
        string $subjectSku,
        ?AdobeProductRemoteGetClassification $remoteGetClassification,
        int $consequentialWriteAttempts,
        int $reconciliationGetAttempts,
        ?AdobeProductWriteAccessClassification $writeAccessClassification,
    ): AdobeProductSimpleCommandResult {
        return new AdobeProductSimpleCommandResult(
            $appliedStateKnowledge,
            new AdobeProductCommandSafeEvidence(
                reasonCode: $reasonCode,
                subjectSku: $subjectSku,
                remoteGetClassification: $remoteGetClassification,
                consequentialWriteAttempts: $consequentialWriteAttempts,
                reconciliationGetAttempts: $reconciliationGetAttempts,
                externalRecordLinkPersisted: false,
                ownershipTrustSatisfied: true,
                writeAccessClassification: $writeAccessClassification,
            ),
        );
    }
}
