<?php

namespace App\Support\Connectors\AdobePaaS\Command;

use App\Support\Connectors\AdobePaaS\AdobePaaSAccessRejectionEvidence;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\Transport\ConnectorHttpResult;

final class AdobeConfigurableParentCommandExecutor
{
    private readonly AdobeProductCreateAttributeValidator $attributeValidator;

    private readonly AdobeProductPlatformCreatedLinkPersister $linkPersister;

    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $linkGuard,
        private readonly AdobePaaSRequestContextFactory $contextFactory,
        private readonly AdobeProductRemoteStateClient $remoteStateClient,
        private readonly AdobeProductRemoteStateComparator $comparator,
        ?AdobeProductCreateAttributeValidator $attributeValidator = null,
        ?AdobeProductPlatformCreatedLinkPersister $linkPersister = null,
    ) {
        $this->attributeValidator = $attributeValidator ?? app(AdobeProductCreateAttributeValidator::class);
        $this->linkPersister = $linkPersister ?? app(AdobeProductPlatformCreatedLinkPersister::class);
    }

    public function preflight(AdobeConfigurableCommandInput $input): ?AdobeConfigurableCommandEvidence
    {
        $lookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $input->desiredState->productId,
        );

        if (! $lookup->isTrusted() && ! $lookup->isAmbiguous()) {
            return $this->inspectCreateAdmission($input);
        }

        return $this->inspectPreWriteState($input)['blockingEvidence'];
    }

    public function execute(AdobeConfigurableCommandInput $input): AdobeConfigurableCommandEvidence
    {
        $lookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $input->desiredState->productId,
        );

        if (! $lookup->isTrusted() && ! $lookup->isAmbiguous()) {
            return $this->executeCreate($input);
        }

        $inspection = $this->inspectPreWriteState($input);

        if ($inspection['blockingEvidence'] !== null) {
            return $inspection['blockingEvidence'];
        }

        $desiredState = $input->desiredState->parent;
        $trustedSku = $inspection['trustedSku'];
        $trustedEntityId = $inspection['trustedEntityId'];
        $context = $inspection['context'];
        $observed = $inspection['observedState'];

        if ($trustedSku === null || $trustedEntityId === null || $context === null || $observed === null) {
            return $this->unknownOrAmbiguous('configurable_parent_preflight_incomplete', $desiredState->sku);
        }

        if ($this->comparator->parentControlledStateMatches($desiredState, $observed)) {
            return $this->knownApplied('configurable_parent_state_already_matches', $trustedSku);
        }

        if (! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $trustedSku, ownershipTrustSatisfied: true);
        }

        [$writeHttp, $writeTransportException] = $this->remoteStateClient->putParentProduct($context, $desiredState);

        if ($writeTransportException === null && $writeHttp !== null
            && in_array($writeHttp->statusCode, [401, 403], true)
            && AdobePaaSAccessRejectionEvidence::hasStructuredResourceDenial($writeHttp->body)
        ) {
            return $this->knownNotApplied(
                'stock_write_permission_denied',
                $trustedSku,
                consequentialWriteAttempts: 1,
                ownershipTrustSatisfied: true,
                writeAccessClassification: AdobeProductWriteAccessClassification::PermissionDenied,
            );
        }

        $reconciled = $this->remoteStateClient->getParentWithContext($context, $trustedSku);
        $postObserved = $reconciled->observedState;

        if ($reconciled->classification === AdobeProductRemoteGetClassification::Found && $postObserved !== null) {
            if ($postObserved->entityId !== $trustedEntityId || $postObserved->typeId !== 'configurable') {
                return $this->unknownOrAmbiguous(
                    'configurable_parent_post_write_identity_mismatch',
                    $trustedSku,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                    ownershipTrustSatisfied: true,
                );
            }

            if ($this->comparator->parentControlledStateMatches($desiredState, $postObserved)) {
                return $this->knownApplied(
                    'stock_write_verified',
                    $trustedSku,
                    consequentialWriteAttempts: 1,
                    reconciliationGetAttempts: 1,
                );
            }
        }

        return $this->unknownOrAmbiguous(
            'configurable_parent_write_postcondition_unverified',
            $trustedSku,
            consequentialWriteAttempts: 1,
            reconciliationGetAttempts: 1,
            ownershipTrustSatisfied: true,
        );
    }

    public function verifyBootstrapNormalized(AdobeConfigurableCommandInput $input): AdobeConfigurableCommandEvidence
    {
        if ($input->desiredState->bootstrapAttributeCodes === []) {
            return $this->knownApplied('configurable_parent_bootstrap_not_required', $input->desiredState->parentSku);
        }

        $lookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $input->desiredState->productId,
        );
        if (! $lookup->isTrusted() || $lookup->link === null) {
            return $this->knownNotApplied('trusted_parent_link_required_for_bootstrap_verification', $input->desiredState->parentSku);
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $read = $this->remoteStateClient->getParentWithContext($context, $input->desiredState->parentSku);
        if ($read->classification !== AdobeProductRemoteGetClassification::Found || $read->observedState === null) {
            return $this->unknownOrAmbiguous('configurable_parent_bootstrap_read_untrusted', $input->desiredState->parentSku);
        }

        foreach ($input->desiredState->bootstrapAttributeCodes as $code) {
            if (array_key_exists($code, $read->observedState->customAttributes)) {
                return $this->knownNotApplied('configurable_parent_bootstrap_not_normalized', $input->desiredState->parentSku, ownershipTrustSatisfied: true);
            }
        }

        return $this->knownApplied('configurable_parent_bootstrap_normalized', $input->desiredState->parentSku);
    }

    private function inspectCreateAdmission(AdobeConfigurableCommandInput $input): ?AdobeConfigurableCommandEvidence
    {
        $desired = $input->desiredState->createParent;

        if ($this->linkGuard->hasAnyParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $input->desiredState->productId,
        )) {
            return $this->knownNotApplied('link_required', $input->desiredState->parentSku);
        }

        if (! $desired instanceof AdobeProductParentDesiredState || $desired->status !== 2) {
            return $this->knownNotApplied('configurable_parent_create_state_invalid', $input->desiredState->parentSku);
        }

        if ($this->linkGuard->hasParentSkuCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desired->sku,
            $desired->productId,
        )) {
            return $this->knownNotApplied('external_record_link_collision', $desired->sku);
        }

        $validation = $this->attributeValidator->validate(
            $input->workspaceId,
            $input->connectorAccountId,
            $desired,
            'configurable',
        );

        if (! $validation->ready) {
            return $this->knownNotApplied($validation->reasonCode, $desired->sku);
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $read = $this->remoteStateClient->getParentWithContext($context, $desired->sku);

        return match ($read->classification) {
            AdobeProductRemoteGetClassification::TrustedKnownMissing => null,
            AdobeProductRemoteGetClassification::Found => $this->knownNotApplied('remote_found_without_trusted_link', $desired->sku),
            default => $this->unknownOrAmbiguous('configurable_parent_initial_get_untrusted', $desired->sku),
        };
    }

    private function executeCreate(AdobeConfigurableCommandInput $input): AdobeConfigurableCommandEvidence
    {
        $blocking = $this->inspectCreateAdmission($input);

        if ($blocking !== null) {
            return $blocking;
        }

        $desired = $input->desiredState->createParent;
        if (! $desired instanceof AdobeProductParentDesiredState || ! $this->permitsConsequentialWrite($input)) {
            return $this->knownNotApplied('writer_lease_expired_before_consequential_write', $input->desiredState->parentSku);
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        [$post, $exception] = $this->remoteStateClient->postParentProduct($context, $desired);

        if ($exception !== null || $post === null || $post->statusCode >= 500 || $post->statusCode === 429) {
            return $this->unknownOrAmbiguous('configurable_parent_create_post_ambiguous', $desired->sku, 1);
        }

        if ($post->statusCode < 200 || $post->statusCode >= 300) {
            return $this->knownNotApplied('configurable_parent_create_post_rejected', $desired->sku, 1);
        }

        $identity = $this->parseSuccessfulPostIdentity($post, $desired->sku);
        if ($identity === null) {
            return $this->unknownOrAmbiguous('configurable_parent_create_identity_inconclusive', $desired->sku, 1);
        }

        $reconciled = $this->remoteStateClient->getParentWithContext($context, $desired->sku);
        if ($reconciled->classification !== AdobeProductRemoteGetClassification::Found
            || $reconciled->observedState === null
            || $reconciled->observedState->entityId !== $identity
            || $reconciled->observedState->typeId !== 'configurable'
            || ! $this->comparator->parentControlledStateMatches($desired, $reconciled->observedState)
        ) {
            return $this->unknownOrAmbiguous('configurable_parent_create_reconciliation_mismatch', $desired->sku, 1, 1);
        }

        try {
            $this->linkPersister->persistProductLink(
                $input->workspaceId,
                $input->connectorAccountId,
                (string) $desired->productId,
                $desired->sku,
                $identity,
            );
        } catch (AdobeProductPlatformCreatedLinkPersistenceException) {
            return $this->unknownOrAmbiguous('configurable_parent_create_link_persistence_failed', $desired->sku, 1, 1, true);
        }

        return $this->knownApplied('configurable_parent_create_post_reconciled', $desired->sku, 1, 1);
    }

    private function parseSuccessfulPostIdentity(ConnectorHttpResult $result, string $expectedSku): ?int
    {
        $payload = json_decode($result->body, true);
        if (is_array($payload['product'] ?? null)) {
            $payload = $payload['product'];
        }

        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        $sku = is_array($payload) ? ($payload['sku'] ?? null) : null;

        if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || $sku !== $expectedSku || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @return array{
     *     blockingEvidence: ?AdobeConfigurableCommandEvidence,
     *     context: ?AdobePaaSRequestContext,
     *     trustedSku: ?string,
     *     trustedEntityId: ?int,
     *     observedState: ?AdobeProductParentObservedState
     * }
     */
    private function inspectPreWriteState(AdobeConfigurableCommandInput $input): array
    {
        $desiredState = $input->desiredState->parent;

        if ($this->linkGuard->hasParentSkuCrossSubjectCollision(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->sku,
            $desiredState->productId,
        )) {
            return $this->blockedInspection($this->knownNotApplied('external_record_link_collision', $desiredState->sku));
        }

        $trustedLookup = $this->linkGuard->resolveTrustedParentLinkBySubject(
            $input->workspaceId,
            $input->connectorAccountId,
            $desiredState->productId,
        );

        if ($trustedLookup->isAmbiguous()) {
            return $this->blockedInspection($this->unknownOrAmbiguous('ambiguous_parent_identity_links', $desiredState->sku));
        }

        if (! $trustedLookup->isTrusted() || $trustedLookup->link === null) {
            return $this->blockedInspection($this->knownNotApplied('link_required', $desiredState->sku));
        }

        $trustedSku = $trustedLookup->link->external_identifier;
        if (! is_string($trustedSku) || $trustedSku === '' || $trustedSku !== $desiredState->sku) {
            return $this->blockedInspection($this->knownNotApplied(
                'trusted_parent_link_sku_mismatch',
                $trustedSku ?: $desiredState->sku,
                ownershipTrustSatisfied: true,
            ));
        }

        $trustedEntityId = $this->parseLogicalEntityId((string) $trustedLookup->link->external_record_discriminator);
        if ($trustedEntityId === null) {
            return $this->blockedInspection($this->knownNotApplied(
                'trusted_parent_discriminator_invalid',
                $trustedSku,
                ownershipTrustSatisfied: true,
            ));
        }

        $context = $this->contextFactory->create($input->workspaceId, $input->connectorAccountId);
        $preRead = $this->remoteStateClient->getParentWithContext($context, $trustedSku);

        if ($preRead->classification === AdobeProductRemoteGetClassification::TrustedKnownMissing) {
            return $this->blockedInspection($this->knownNotApplied(
                'linked_remote_parent_missing',
                $trustedSku,
                ownershipTrustSatisfied: true,
            ));
        }

        $observed = $preRead->observedState;
        if ($preRead->classification !== AdobeProductRemoteGetClassification::Found || $observed === null) {
            return $this->blockedInspection($this->unknownOrAmbiguous(
                'configurable_parent_pre_read_untrusted_or_failed',
                $trustedSku,
                ownershipTrustSatisfied: true,
            ));
        }

        if ($observed->entityId !== $trustedEntityId) {
            return $this->blockedInspection($this->knownNotApplied(
                'configurable_parent_identity_mismatch',
                $trustedSku,
                ownershipTrustSatisfied: true,
            ));
        }

        if ($desiredState->typeId !== 'configurable' || $observed->typeId !== 'configurable') {
            return $this->blockedInspection($this->knownNotApplied(
                'configurable_parent_type_mismatch',
                $trustedSku,
                ownershipTrustSatisfied: true,
            ));
        }

        if (! $this->comparator->parentControlledStateMatches($desiredState, $observed)
            && $preRead->mediaRoleLabelMaterializationSafe !== true
        ) {
            return $this->blockedInspection($this->knownNotApplied(
                'configurable_parent_media_role_label_side_effect_not_safe',
                $trustedSku,
                ownershipTrustSatisfied: true,
            ));
        }

        return [
            'blockingEvidence' => null,
            'context' => $context,
            'trustedSku' => $trustedSku,
            'trustedEntityId' => $trustedEntityId,
            'observedState' => $observed,
        ];
    }

    /**
     * @return array{
     *     blockingEvidence: AdobeConfigurableCommandEvidence,
     *     context: null,
     *     trustedSku: null,
     *     trustedEntityId: null,
     *     observedState: null
     * }
     */
    private function blockedInspection(AdobeConfigurableCommandEvidence $evidence): array
    {
        return [
            'blockingEvidence' => $evidence,
            'context' => null,
            'trustedSku' => null,
            'trustedEntityId' => null,
            'observedState' => null,
        ];
    }

    private function permitsConsequentialWrite(AdobeConfigurableCommandInput $input): bool
    {
        return $input->consequentialWriteGate === null
            || ($input->consequentialWriteGate->permitsConsequentialWrite()
                && $input->consequentialWriteGate->permitsProductExecution());
    }

    private function parseLogicalEntityId(string $discriminator): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/', $discriminator) !== 1) {
            return null;
        }

        $entityId = (int) $discriminator;

        return (string) $entityId === $discriminator ? $entityId : null;
    }

    private function knownApplied(
        string $reasonCode,
        string $subjectSku,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            ownershipTrustSatisfied: true,
        );
    }

    private function knownNotApplied(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
        ?AdobeProductWriteAccessClassification $writeAccessClassification = null,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::KnownNotApplied,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            ownershipTrustSatisfied: $ownershipTrustSatisfied,
            writeAccessClassification: $writeAccessClassification,
        );
    }

    private function unknownOrAmbiguous(
        string $reasonCode,
        ?string $subjectSku = null,
        int $consequentialWriteAttempts = 0,
        int $reconciliationGetAttempts = 0,
        bool $ownershipTrustSatisfied = false,
    ): AdobeConfigurableCommandEvidence {
        return new AdobeConfigurableCommandEvidence(
            commandKind: 'configurable_parent',
            appliedStateKnowledge: AdobeProductAppliedStateKnowledge::UnknownOrAmbiguous,
            reasonCode: $reasonCode,
            subjectSku: $subjectSku,
            consequentialWriteAttempts: $consequentialWriteAttempts,
            reconciliationGetAttempts: $reconciliationGetAttempts,
            ownershipTrustSatisfied: $ownershipTrustSatisfied,
        );
    }
}
