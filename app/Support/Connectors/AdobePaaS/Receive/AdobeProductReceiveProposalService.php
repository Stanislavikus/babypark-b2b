<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Enums\ReceiveDomainRoute;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncSemanticOperation;
use App\Exceptions\Catalog\InvalidColumnFieldValueException;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Services\Catalog\GovernedProductVariantColumnEligibility;
use App\Services\Catalog\GovernedProductVariantColumnValuePolicy;
use App\Services\Sync\FieldMappingBindingValidator;
use App\Services\Sync\Receive\ReceiveProposalFlowStore;
use App\Services\Sync\Receive\ReceiveProposalPlanner;
use App\Services\Sync\SyncConfigurationLookupService;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductExternalRecordLinkGuard;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductTrustedParentLinkLookup;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductTrustedVariantLinkLookup;
use App\Support\Connectors\AdobePaaS\Exceptions\IncompleteAdobePaaSCredentialsException;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReadException;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReader;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Receive\ReceiveFieldCandidate;
use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use DateTimeImmutable;

final class AdobeProductReceiveProposalService
{
    public function __construct(
        private readonly AdobeProductExternalRecordLinkGuard $externalRecordLinkGuard,
        private readonly AdobeProductDocumentReader $productDocumentReader,
        private readonly SyncConfigurationLookupService $configurationLookup,
        private readonly FieldMappingBindingValidator $fieldMappingBindingValidator,
        private readonly GovernedProductVariantColumnEligibility $columnEligibility,
        private readonly GovernedProductVariantColumnValuePolicy $columnValuePolicy,
        private readonly ReceiveProposalPlanner $proposalPlanner,
        private readonly ReceiveProposalFlowStore $proposalFlowStore,
    ) {}

    /**
     * Internal runtime primitive only.
     * Actor binding attaches the transient flow to a future consumer but does
     * not authorize Receive or Apply.
     */
    public function build(
        int|string $actorUserId,
        string $workspaceId,
        string $connectorAccountId,
        FieldObjectType $targetType,
        int|string $targetId,
    ): AdobeProductReceiveProposalResult {
        $targetId = (string) $targetId;
        $account = $this->loadConnectorAccount($workspaceId, $connectorAccountId);
        $configuration = $this->loadEligibleConfiguration($account);
        $configurationRevision = (string) $configuration->configuration_revision;

        if ($configurationRevision === '') {
            throw AdobeProductReceiveProposalException::invalidConfigurationRevision($configuration->id);
        }

        $this->resolveTargetForWorkspace(
            workspaceId: $workspaceId,
            targetType: $targetType,
            targetId: $targetId,
        );

        $trustedLink = $this->resolveTrustedLink(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            targetType: $targetType,
            targetId: $targetId,
        );

        $logicalEntityId = $this->parseLogicalEntityId((string) $trustedLink->external_record_discriminator);
        $expectedSku = $this->parseExpectedSku($trustedLink->external_identifier);

        try {
            $verifiedProduct = $this->productDocumentReader->read(
                $workspaceId,
                $connectorAccountId,
                $expectedSku,
            );
        } catch (ConnectorAccountNotFoundException|IncompleteAdobePaaSCredentialsException $exception) {
            throw AdobeProductReceiveProposalException::productReadContextInvalid($exception);
        } catch (AdobeProductDocumentReadException $exception) {
            throw AdobeProductReceiveProposalException::productReadFailed(
                $this->classifyProductReadFailure($exception),
                $exception,
            );
        }

        if ($verifiedProduct->logicalEntityId !== $logicalEntityId || $verifiedProduct->sku !== $expectedSku) {
            throw AdobeProductReceiveProposalException::trustedExternalLinkChanged();
        }

        $remoteName = $verifiedProduct->externalValue('name');
        $remoteNameValue = is_string($remoteName['value'] ?? null) ? $remoteName['value'] : '';

        $revalidatedConfiguration = $this->revalidateConfiguration(
            account: $account,
            expectedConfigurationId: $configuration->id,
            expectedRevision: $configurationRevision,
        );

        $revalidatedLink = $this->revalidateTrustedLink(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            targetType: $targetType,
            targetId: $targetId,
            expectedLinkId: (string) $trustedLink->id,
            expectedLogicalEntityId: $logicalEntityId,
            expectedSku: $expectedSku,
        );

        $mappingState = $this->resolveNameMappingState($revalidatedConfiguration);
        $freshLocalProduct = $this->reloadFreshLocalProduct(
            workspaceId: $workspaceId,
            targetType: $targetType,
            targetId: $targetId,
        );
        $mappingState = $this->applyNameValueExecutability(
            $mappingState,
            $remoteNameValue,
        );

        $entries = $this->proposalPlanner->plan([
            $this->buildNameCandidate(
                fieldBindingId: $mappingState['field_binding_id'],
                localName: $freshLocalProduct->name,
                remoteName: $remoteNameValue,
                isSupported: $mappingState['is_supported'],
                blockedReasonCode: $mappingState['blocked_reason_code'],
            ),
        ]);

        $finalConfiguration = $this->revalidateConfiguration(
            account: $account,
            expectedConfigurationId: $configuration->id,
            expectedRevision: $configurationRevision,
        );

        $proposal = new ReceiveProposal(
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            syncConfigurationId: $finalConfiguration->id,
            configurationRevision: $configurationRevision,
            targetType: $targetType,
            targetId: $targetId,
            trustedExternalLinkEvidenceId: (string) $revalidatedLink->id,
            entries: $entries,
            issuedAt: $this->issuedAt(),
        );

        $binding = new ReceiveProposalFlowBinding(
            actorUserId: $actorUserId,
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            syncConfigurationId: $finalConfiguration->id,
            targetType: $targetType,
            targetId: $targetId,
        );

        return new AdobeProductReceiveProposalResult(
            flowId: $this->proposalFlowStore->issue($binding, $proposal),
            proposal: $proposal,
        );
    }

    private function loadConnectorAccount(string $workspaceId, string $connectorAccountId): ConnectorAccount
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereKey($connectorAccountId)
            ->first();

        if ($account === null) {
            throw AdobeProductReceiveProposalException::connectorAccountNotFound($connectorAccountId, $workspaceId);
        }

        return $account;
    }

    private function loadEligibleConfiguration(ConnectorAccount $account): SyncConfiguration
    {
        $configuration = $this->configurationLookup->findProductsDefaultContext($account);

        if ($configuration === null) {
            throw AdobeProductReceiveProposalException::configurationNotFound();
        }

        $this->assertConfigurationEligible($configuration);

        return $configuration;
    }

    private function revalidateConfiguration(
        ConnectorAccount $account,
        string $expectedConfigurationId,
        string $expectedRevision,
    ): SyncConfiguration {
        $configuration = $this->loadEligibleConfiguration($account);

        if (
            $configuration->id !== $expectedConfigurationId
            || (string) $configuration->configuration_revision !== $expectedRevision
        ) {
            throw AdobeProductReceiveProposalException::configurationChanged();
        }

        return $configuration;
    }

    private function assertConfigurationEligible(SyncConfiguration $configuration): void
    {
        try {
            $this->fieldMappingBindingValidator->assertProductsConfiguration($configuration);
        } catch (FieldMappingValidationException $exception) {
            throw AdobeProductReceiveProposalException::configurationNotFound();
        }

        if ($configuration->operational_state !== SyncConfigurationOperationalState::Enabled) {
            throw AdobeProductReceiveProposalException::configurationNotEnabled($configuration->id);
        }

        if (! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Import)) {
            throw AdobeProductReceiveProposalException::importNotEnabled($configuration->id);
        }
    }

    private function resolveTargetForWorkspace(
        string $workspaceId,
        FieldObjectType $targetType,
        string $targetId,
    ): void {
        if ($targetType === FieldObjectType::Product) {
            $product = Product::withoutWorkspaceScope()->whereKey($targetId)->first();

            if ($product === null) {
                throw AdobeProductReceiveProposalException::targetNotFound($targetType->value, $targetId);
            }

            if ($product->workspace_id !== $workspaceId) {
                throw AdobeProductReceiveProposalException::targetWorkspaceMismatch($targetType->value, $targetId);
            }

            return;
        }

        if ($targetType === FieldObjectType::ProductVariant) {
            $variant = $this->loadVariantForWorkspace($workspaceId, $targetId);
            $this->loadOwningProductForVariant($workspaceId, $variant);

            return;
        }

        throw AdobeProductReceiveProposalException::targetNotFound($targetType->value, $targetId);
    }

    private function resolveTrustedLink(
        string $workspaceId,
        string $connectorAccountId,
        FieldObjectType $targetType,
        string $targetId,
    ): ExternalRecordLink {
        if ($targetType === FieldObjectType::Product) {
            $lookup = $this->externalRecordLinkGuard->resolveTrustedParentLinkBySubject(
                $workspaceId,
                $connectorAccountId,
                (int) $targetId,
            );

            return $this->resolveTrustedParentLinkOrFail($lookup);
        }

        if ($targetType === FieldObjectType::ProductVariant) {
            $lookup = $this->externalRecordLinkGuard->resolveTrustedVariantLinkBySubject(
                $workspaceId,
                $connectorAccountId,
                $targetId,
            );

            return $this->resolveTrustedVariantLinkOrFail($lookup);
        }

        throw AdobeProductReceiveProposalException::targetNotFound($targetType->value, $targetId);
    }

    private function revalidateTrustedLink(
        string $workspaceId,
        string $connectorAccountId,
        FieldObjectType $targetType,
        string $targetId,
        string $expectedLinkId,
        int $expectedLogicalEntityId,
        string $expectedSku,
    ): ExternalRecordLink {
        $trustedLink = $this->resolveTrustedLink(
            $workspaceId,
            $connectorAccountId,
            $targetType,
            $targetId,
        );

        $actualLogicalEntityId = $this->parseLogicalEntityId((string) $trustedLink->external_record_discriminator);
        $actualExpectedSku = $this->parseExpectedSku($trustedLink->external_identifier);

        if (
            (string) $trustedLink->id !== $expectedLinkId
            || $actualLogicalEntityId !== $expectedLogicalEntityId
            || $actualExpectedSku !== $expectedSku
        ) {
            throw AdobeProductReceiveProposalException::trustedExternalLinkChanged();
        }

        return $trustedLink;
    }

    private function resolveTrustedParentLinkOrFail(
        AdobeProductTrustedParentLinkLookup $lookup,
    ): ExternalRecordLink {
        if ($lookup->isAmbiguous()) {
            throw AdobeProductReceiveProposalException::trustedExternalLinkAmbiguous();
        }

        if (! $lookup->isTrusted() || $lookup->link === null) {
            throw AdobeProductReceiveProposalException::trustedExternalLinkMissing();
        }

        return $lookup->link;
    }

    private function resolveTrustedVariantLinkOrFail(
        AdobeProductTrustedVariantLinkLookup $lookup,
    ): ExternalRecordLink {
        if ($lookup->isAmbiguous()) {
            throw AdobeProductReceiveProposalException::trustedExternalLinkAmbiguous();
        }

        if (! $lookup->isTrusted() || $lookup->link === null) {
            throw AdobeProductReceiveProposalException::trustedExternalLinkMissing();
        }

        return $lookup->link;
    }

    private function parseLogicalEntityId(string $externalRecordDiscriminator): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $externalRecordDiscriminator) !== 1) {
            throw AdobeProductReceiveProposalException::invalidTrustedLogicalEntityId($externalRecordDiscriminator);
        }

        $logicalEntityId = (int) $externalRecordDiscriminator;

        if ((string) $logicalEntityId !== $externalRecordDiscriminator) {
            throw AdobeProductReceiveProposalException::invalidTrustedLogicalEntityId($externalRecordDiscriminator);
        }

        return $logicalEntityId;
    }

    private function parseExpectedSku(mixed $externalIdentifier): string
    {
        if (! is_string($externalIdentifier) || $externalIdentifier === '' || trim($externalIdentifier) !== $externalIdentifier) {
            throw AdobeProductReceiveProposalException::invalidTrustedExpectedSku();
        }

        return $externalIdentifier;
    }

    /**
     * @return array{field_binding_id: string, is_supported: bool, blocked_reason_code: ?string}
     */
    private function resolveNameMappingState(SyncConfiguration $configuration): array
    {
        $mappings = FieldMapping::withoutWorkspaceScope()
            ->where('workspace_id', $configuration->workspace_id)
            ->where('sync_configuration_id', $configuration->id)
            ->where('external_field_key', 'name')
            ->get();

        if ($mappings->count() > 1) {
            throw AdobeProductReceiveProposalException::fieldMappingAmbiguous('name');
        }

        /** @var FieldMapping|null $mapping */
        $mapping = $mappings->first();

        if ($mapping === null) {
            throw AdobeProductReceiveProposalException::noExecutableNameMapping();
        }

        $binding = FieldBinding::withoutWorkspaceScope()->find($mapping->field_binding_id);

        if ($binding === null) {
            throw AdobeProductReceiveProposalException::persistenceInvariantBroken(
                "Field mapping '{$mapping->id}' references missing field binding '{$mapping->field_binding_id}'.",
            );
        }

        $definition = FieldDefinition::withoutWorkspaceScope()->find($binding->field_definition_id);

        if ($definition === null) {
            throw AdobeProductReceiveProposalException::persistenceInvariantBroken(
                "Field binding '{$binding->id}' references missing field definition '{$binding->field_definition_id}'.",
            );
        }

        if ($binding->workspace_id !== null && $binding->workspace_id !== $configuration->workspace_id) {
            throw AdobeProductReceiveProposalException::persistenceInvariantBroken(
                "Field binding '{$binding->id}' is outside the sync configuration workspace.",
            );
        }

        if ($definition->workspace_id !== null && $definition->workspace_id !== $configuration->workspace_id) {
            throw AdobeProductReceiveProposalException::persistenceInvariantBroken(
                "Field definition '{$definition->id}' is outside the sync configuration workspace.",
            );
        }

        if ($binding->status !== AttributeStatus::Active) {
            return [
                'field_binding_id' => (string) $binding->id,
                'is_supported' => false,
                'blocked_reason_code' => 'binding_inactive',
            ];
        }

        if ($definition->status !== AttributeStatus::Active) {
            return [
                'field_binding_id' => (string) $binding->id,
                'is_supported' => false,
                'blocked_reason_code' => 'definition_inactive',
            ];
        }

        if (! in_array($binding->object_type, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            return [
                'field_binding_id' => (string) $binding->id,
                'is_supported' => false,
                'blocked_reason_code' => 'name_mapping_object_type_blocked',
            ];
        }

        try {
            $this->fieldMappingBindingValidator->assertEligibleBinding($configuration, $mapping->field_binding_id);
        } catch (FieldMappingValidationException $exception) {
            throw AdobeProductReceiveProposalException::persistenceInvariantBroken(
                "Field mapping '{$mapping->id}' failed binding validation during Receive proposal build.",
            );
        }

        if (! $this->columnEligibility->isCanonicalField($binding, $definition, 'name')) {
            return [
                'field_binding_id' => (string) $binding->id,
                'is_supported' => false,
                'blocked_reason_code' => 'name_mapping_not_canonical',
            ];
        }

        return [
            'field_binding_id' => (string) $binding->id,
            'is_supported' => true,
            'blocked_reason_code' => null,
        ];
    }

    private function reloadFreshLocalProduct(
        string $workspaceId,
        FieldObjectType $targetType,
        string $targetId,
    ): Product {
        if ($targetType === FieldObjectType::Product) {
            $product = Product::withoutWorkspaceScope()->whereKey($targetId)->first();

            if ($product === null) {
                throw AdobeProductReceiveProposalException::targetNotFound($targetType->value, $targetId);
            }

            if ($product->workspace_id !== $workspaceId) {
                throw AdobeProductReceiveProposalException::targetWorkspaceMismatch($targetType->value, $targetId);
            }

            return $product;
        }

        $variant = $this->loadVariantForWorkspace($workspaceId, $targetId);

        return $this->loadOwningProductForVariant($workspaceId, $variant);
    }

    /**
     * @param  array{field_binding_id: string, is_supported: bool, blocked_reason_code: ?string}  $mappingState
     * @return array{field_binding_id: string, is_supported: bool, blocked_reason_code: ?string}
     */
    private function applyNameValueExecutability(array $mappingState, string $remoteName): array
    {
        if (! $mappingState['is_supported']) {
            return $mappingState;
        }

        try {
            $this->columnValuePolicy->normalizeSetValue('name', $remoteName);
        } catch (InvalidColumnFieldValueException) {
            return [
                'field_binding_id' => $mappingState['field_binding_id'],
                'is_supported' => false,
                'blocked_reason_code' => 'name_value_not_executable',
            ];
        }

        return $mappingState;
    }

    private function loadVariantForWorkspace(string $workspaceId, string $targetId): ProductVariant
    {
        $variant = ProductVariant::withoutWorkspaceScope()
            ->whereKey($targetId)
            ->first();

        if ($variant === null) {
            throw AdobeProductReceiveProposalException::targetNotFound(FieldObjectType::ProductVariant->value, $targetId);
        }

        if ($variant->workspace_id !== $workspaceId) {
            throw AdobeProductReceiveProposalException::targetWorkspaceMismatch(FieldObjectType::ProductVariant->value, $targetId);
        }

        return $variant;
    }

    private function loadOwningProductForVariant(string $workspaceId, ProductVariant $variant): Product
    {
        $product = Product::withoutWorkspaceScope()
            ->whereKey($variant->product_id)
            ->first();

        if ($product === null || $product->workspace_id !== $workspaceId) {
            throw AdobeProductReceiveProposalException::invalidVariantOwnership((string) $variant->id);
        }

        return $product;
    }

    private function buildNameCandidate(
        string $fieldBindingId,
        string $localName,
        string $remoteName,
        bool $isSupported,
        ?string $blockedReasonCode,
    ): ReceiveFieldCandidate {
        return new ReceiveFieldCandidate(
            fieldBindingId: $fieldBindingId,
            objectType: FieldObjectType::Product,
            domainRoute: $isSupported ? ReceiveDomainRoute::ProductVariantColumn : ReceiveDomainRoute::Unsupported,
            localValuePresent: true,
            localCanonicalValue: $localName,
            remoteValuePresent: true,
            remoteCanonicalValue: $remoteName,
            explicitClear: false,
            isSupported: $isSupported,
            blockedReasonCode: $blockedReasonCode,
        );
    }

    private function classifyProductReadFailure(AdobeProductDocumentReadException $exception): string
    {
        return match ($exception->getMessage()) {
            'Magento Product document read transport failed.' => 'product_read_transport_failure',
            default => 'product_read_failed',
        };
    }

    private function issuedAt(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now());
    }
}
