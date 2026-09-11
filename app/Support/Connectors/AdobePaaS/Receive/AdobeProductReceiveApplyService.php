<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Enums\ReceiveDiffState;
use App\Enums\ReceiveDomainRoute;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Exceptions\Catalog\ColumnFieldCurrentValueMismatchException;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\FieldMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\GovernedProductVariantColumnEligibility;
use App\Services\Catalog\GovernedProductVariantColumnMutationService;
use App\Services\Sync\Receive\ReceiveLiveImportAdmissionService;
use App\Services\Sync\Receive\ReceiveProposalFlowStore;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReader;
use App\Support\Connectors\AdobePaaS\Product\AdobeProductDocumentReadException;
use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Sync\Receive\ReceiveProposalEntry;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdobeProductReceiveApplyService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly ReceiveProposalFlowStore $proposalFlowStore,
        private readonly ReceiveLiveImportAdmissionService $admissionService,
        private readonly AdobeProductDocumentReader $productDocumentReader,
        private readonly GovernedProductVariantColumnEligibility $columnEligibility,
        private readonly GovernedProductVariantColumnMutationService $columnMutationService,
    ) {}

    public function apply(
        User $actor,
        string $flowId,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        FieldObjectType $targetType,
        int|string $targetId,
    ): SyncRun {
        $targetId = (string) $targetId;
        [$workspace, $account] = $this->loadContext($workspaceId, $connectorAccountId);

        // Frozen ordering: do not consume a single-use proposal for an actor who
        // no longer has consequential Live authority.
        if (! $this->authorization->allows($actor, $workspace, WorkspacePermissions::RUN_SYNC_LIVE)) {
            throw AdobeProductReceiveApplyException::notAuthorized();
        }

        $binding = new ReceiveProposalFlowBinding(
            actorUserId: $actor->id,
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            syncConfigurationId: $syncConfigurationId,
            targetType: $targetType,
            targetId: $targetId,
        );

        $proposal = $this->proposalFlowStore->consume($flowId, $binding);

        if (! $proposal instanceof ReceiveProposal) {
            throw AdobeProductReceiveApplyException::proposalUnavailable();
        }

        $entry = $this->assertExecutableProposalShape($proposal);
        $productId = $this->resolveOwningProductId($proposal);
        $this->assertCurrentNameMapping($proposal, $entry);

        // Admission performs the second fresh authority check inside the locked
        // workspace/configuration transaction and creates the synchronous
        // Running Live/Import run. No remote HTTP happens in admission.
        $run = $this->admissionService->admit($actor, $account, $proposal, $productId);

        try {
            $this->assertTrustedLinkMatchesProposal($proposal, lock: false);
            $verifiedProduct = $this->productDocumentReader->read(
                $proposal->workspaceId,
                $proposal->connectorAccountId,
                $proposal->trustedExternalIdentifier,
            );
        } catch (AdobeProductDocumentReadException $exception) {
            return $this->completeNotApplied(
                $run,
                $productId,
                AdobeProductReceiveApplyException::remoteReadFailed($exception)->reasonCode,
            );
        } catch (AdobeProductReceiveApplyException $exception) {
            return $this->completeNotApplied($run, $productId, $exception->reasonCode);
        }

        if (
            $verifiedProduct->logicalEntityId !== $this->parseLogicalEntityId($proposal->trustedExternalRecordDiscriminator)
            || $verifiedProduct->sku !== $proposal->trustedExternalIdentifier
        ) {
            return $this->completeNotApplied(
                $run,
                $productId,
                AdobeProductReceiveApplyException::remoteIdentityChanged()->reasonCode,
            );
        }

        $remoteName = $verifiedProduct->externalValue('name');

        if (
            ! ($remoteName['present'] ?? false)
            || ! is_string($remoteName['value'] ?? null)
            || $remoteName['value'] !== $entry->remoteCanonicalValue
        ) {
            return $this->completeNotApplied(
                $run,
                $productId,
                AdobeProductReceiveApplyException::remoteValueChanged()->reasonCode,
            );
        }

        try {
            return $this->mutateAndComplete(
                run: $run,
                proposal: $proposal,
                entry: $entry,
                productId: $productId,
            );
        } catch (ColumnFieldCurrentValueMismatchException $exception) {
            return $this->completeNotApplied(
                $run,
                $productId,
                AdobeProductReceiveApplyException::localValueChanged($exception)->reasonCode,
            );
        } catch (AdobeProductReceiveApplyException $exception) {
            if ($exception->reasonCode === 'receive_apply_run_not_executable') {
                $this->failRun($run);

                throw $exception;
            }

            return $this->completeNotApplied($run, $productId, $exception->reasonCode);
        } catch (\Throwable $exception) {
            $this->failRun($run);

            throw $exception;
        }
    }

    /** @return array{0: Workspace, 1: ConnectorAccount} */
    private function loadContext(string $workspaceId, string $connectorAccountId): array
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereKey($connectorAccountId)
            ->first();

        if (! $workspace instanceof Workspace || ! $account instanceof ConnectorAccount) {
            throw AdobeProductReceiveApplyException::contextInvalid();
        }

        return [$workspace, $account];
    }

    private function assertExecutableProposalShape(ReceiveProposal $proposal): ReceiveProposalEntry
    {
        if (count($proposal->entries) !== 1) {
            throw AdobeProductReceiveApplyException::proposalShapeNotExecutable();
        }

        $entry = $proposal->entries[0] ?? null;

        if (
            ! $entry instanceof ReceiveProposalEntry
            || $entry->objectType !== FieldObjectType::Product
            || $entry->domainRoute !== ReceiveDomainRoute::ProductVariantColumn
            || $entry->diffState !== ReceiveDiffState::Differs
            || ! $entry->localValuePresent
            || ! is_string($entry->localCanonicalValue)
            || ! $entry->remoteValuePresent
            || ! is_string($entry->remoteCanonicalValue)
            || $entry->explicitClear
            || $entry->blockedReasonCode !== null
        ) {
            throw AdobeProductReceiveApplyException::proposalShapeNotExecutable();
        }

        return $entry;
    }

    private function resolveOwningProductId(ReceiveProposal $proposal): string
    {
        if ($proposal->targetType === FieldObjectType::Product) {
            $product = Product::withoutWorkspaceScope()->whereKey($proposal->targetId)->first();

            if (! $product instanceof Product || $product->workspace_id !== $proposal->workspaceId) {
                throw AdobeProductReceiveApplyException::proposalShapeNotExecutable();
            }

            return (string) $product->id;
        }

        if ($proposal->targetType === FieldObjectType::ProductVariant) {
            $variant = ProductVariant::withoutWorkspaceScope()->whereKey($proposal->targetId)->first();

            if (! $variant instanceof ProductVariant || $variant->workspace_id !== $proposal->workspaceId) {
                throw AdobeProductReceiveApplyException::proposalShapeNotExecutable();
            }

            $product = Product::withoutWorkspaceScope()->whereKey($variant->product_id)->first();

            if (! $product instanceof Product || $product->workspace_id !== $proposal->workspaceId) {
                throw AdobeProductReceiveApplyException::proposalShapeNotExecutable();
            }

            return (string) $product->id;
        }

        throw AdobeProductReceiveApplyException::proposalShapeNotExecutable();
    }

    private function assertCurrentNameMapping(ReceiveProposal $proposal, ReceiveProposalEntry $entry): void
    {
        $mappings = FieldMapping::withoutWorkspaceScope()
            ->where('workspace_id', $proposal->workspaceId)
            ->where('sync_configuration_id', $proposal->syncConfigurationId)
            ->where('external_field_key', 'name')
            ->get();

        if ($mappings->count() !== 1 || (string) $mappings->first()->field_binding_id !== $entry->fieldBindingId) {
            throw AdobeProductReceiveApplyException::mappingChanged();
        }

        $binding = FieldBinding::withoutWorkspaceScope()->whereKey($entry->fieldBindingId)->first();
        $definition = $binding instanceof FieldBinding
            ? FieldDefinition::withoutWorkspaceScope()->whereKey($binding->field_definition_id)->first()
            : null;

        if (
            ! $binding instanceof FieldBinding
            || ! $definition instanceof FieldDefinition
            || $binding->status !== AttributeStatus::Active
            || $definition->status !== AttributeStatus::Active
            || ! $this->columnEligibility->isCanonicalField($binding, $definition, 'name')
        ) {
            throw AdobeProductReceiveApplyException::mappingChanged();
        }
    }

    private function assertTrustedLinkMatchesProposal(ReceiveProposal $proposal, bool $lock): ExternalRecordLink
    {
        $query = ExternalRecordLink::withoutWorkspaceScope()
            ->where('workspace_id', $proposal->workspaceId)
            ->where('connector_account_id', $proposal->connectorAccountId)
            ->whereKey($proposal->trustedExternalLinkEvidenceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $link = $query->first();

        if (
            ! $link instanceof ExternalRecordLink
            || ! $link->hasMerchantConfirmedTrust()
            || (string) $link->external_identifier !== $proposal->trustedExternalIdentifier
            || (string) $link->external_record_discriminator !== $proposal->trustedExternalRecordDiscriminator
            || ! $this->linkTargetsProposalSubject($link, $proposal)
        ) {
            throw AdobeProductReceiveApplyException::trustedLinkChanged();
        }

        return $link;
    }

    private function linkTargetsProposalSubject(ExternalRecordLink $link, ReceiveProposal $proposal): bool
    {
        return match ($proposal->targetType) {
            FieldObjectType::Product => $link->product_id !== null
                && (string) $link->product_id === $proposal->targetId
                && $link->product_variant_id === null,
            FieldObjectType::ProductVariant => $link->product_variant_id !== null
                && (string) $link->product_variant_id === $proposal->targetId
                && $link->product_id === null,
            default => false,
        };
    }

    private function mutateAndComplete(
        SyncRun $run,
        ReceiveProposal $proposal,
        ReceiveProposalEntry $entry,
        string $productId,
    ): SyncRun {
        DB::transaction(function () use ($run, $proposal, $entry, $productId): void {
            $lockedRun = SyncRun::withoutWorkspaceScope()
                ->where('workspace_id', $proposal->workspaceId)
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedRun instanceof SyncRun
                || $lockedRun->status !== SyncRunStatus::Running
                || $lockedRun->mode !== SyncRunMode::Live
                || $lockedRun->semantic_operation !== SyncSemanticOperation::Import
                || $lockedRun->writer_deadline_at === null
                || ! now()->lessThan($lockedRun->writer_deadline_at)
            ) {
                throw AdobeProductReceiveApplyException::runNotExecutable();
            }

            $configuration = SyncConfiguration::withoutWorkspaceScope()
                ->where('workspace_id', $proposal->workspaceId)
                ->where('connector_account_id', $proposal->connectorAccountId)
                ->whereKey($proposal->syncConfigurationId)
                ->lockForUpdate()
                ->first();

            if (
                ! $configuration instanceof SyncConfiguration
                || (string) $configuration->configuration_revision !== $proposal->configurationRevision
                || $configuration->operational_state !== SyncConfigurationOperationalState::Enabled
                || ! $configuration->enabledOperationSet()->contains(SyncSemanticOperation::Import)
            ) {
                throw AdobeProductReceiveApplyException::configurationChanged();
            }

            $this->assertTrustedLinkMatchesProposal($proposal, lock: true);

            $mapping = FieldMapping::withoutWorkspaceScope()
                ->where('workspace_id', $proposal->workspaceId)
                ->where('sync_configuration_id', $proposal->syncConfigurationId)
                ->where('external_field_key', 'name')
                ->lockForUpdate()
                ->get();

            if ($mapping->count() !== 1 || (string) $mapping->first()->field_binding_id !== $entry->fieldBindingId) {
                throw AdobeProductReceiveApplyException::mappingChanged();
            }

            $mutation = $this->columnMutationService->setIfCurrentValue(
                workspaceId: $proposal->workspaceId,
                targetType: FieldObjectType::Product,
                targetId: $productId,
                fieldBindingId: $entry->fieldBindingId,
                expectedCurrentValue: $entry->localCanonicalValue,
                value: $entry->remoteCanonicalValue,
            );

            SyncRunItem::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $proposal->workspaceId,
                'sync_run_id' => $lockedRun->id,
                'product_id' => (int) $productId,
                'outcome' => SyncLiveOutcome::Synchronized->value,
                'findings' => [[
                    'code' => 'receive_product_name_applied',
                    'field_binding_id' => $mutation->fieldBindingId,
                    'mutation_status' => $mutation->status,
                ]],
            ]);

            $lockedRun->update([
                'status' => SyncRunStatus::Completed,
                'completed_at' => now(),
            ]);
        });

        return $run->fresh();
    }

    private function completeNotApplied(SyncRun $run, string $productId, string $reasonCode): SyncRun
    {
        DB::transaction(function () use ($run, $productId, $reasonCode): void {
            $lockedRun = SyncRun::withoutWorkspaceScope()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRun instanceof SyncRun || $lockedRun->status !== SyncRunStatus::Running) {
                return;
            }

            SyncRunItem::withoutWorkspaceScope()->firstOrCreate(
                [
                    'sync_run_id' => $lockedRun->id,
                    'product_id' => (int) $productId,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $lockedRun->workspace_id,
                    'outcome' => SyncLiveOutcome::NotApplied->value,
                    'findings' => [['code' => $reasonCode]],
                ],
            );

            $lockedRun->update([
                'status' => SyncRunStatus::Completed,
                'completed_at' => now(),
            ]);
        });

        return $run->fresh();
    }

    private function failRun(SyncRun $run): void
    {
        SyncRun::withoutWorkspaceScope()
            ->whereKey($run->id)
            ->where('status', SyncRunStatus::Running)
            ->update([
                'status' => SyncRunStatus::Failed,
                'completed_at' => now(),
            ]);
    }

    private function parseLogicalEntityId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw AdobeProductReceiveApplyException::trustedLinkChanged();
        }

        $parsed = (int) $value;

        if ((string) $parsed !== $value) {
            throw AdobeProductReceiveApplyException::trustedLinkChanged();
        }

        return $parsed;
    }
}
