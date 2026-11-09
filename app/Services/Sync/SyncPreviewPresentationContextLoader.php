<?php

namespace App\Services\Sync;

use App\Models\FieldBinding;
use App\Models\ProductVariant;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Sync\Preview\Presentation\SyncPreviewFindingReferenceResolver;
use App\Support\Sync\Preview\Presentation\SyncPreviewPresentationContext;
use Illuminate\Support\Collection;

final class SyncPreviewPresentationContextLoader
{
    public function __construct(
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly AdobeProductsExportPreviewAuthorizationService $authorizationService,
        private readonly SyncPreviewFindingReferenceResolver $referenceResolver,
    ) {}

    public function loadForRun(
        User $actor,
        Workspace $workspace,
        string $accountId,
        ?SyncConfiguration $configuration,
        SyncRun $run,
        ?Collection $items = null,
    ): SyncPreviewPresentationContext {
        $snapshot = is_array($run->configuration_snapshot) ? $run->configuration_snapshot : [];
        $items ??= SyncRunItem::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('sync_run_id', $run->id)
            ->get();

        return $this->load(
            actor: $actor,
            workspace: $workspace,
            accountId: $accountId,
            configuration: $configuration,
            snapshot: $snapshot,
            items: $items,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, SyncRunItem>  $items
     */
    public function load(
        User $actor,
        Workspace $workspace,
        string $accountId,
        ?SyncConfiguration $configuration,
        array $snapshot,
        Collection $items,
    ): SyncPreviewPresentationContext {
        $bindingIds = [];
        $variantIds = [];

        foreach ($items as $item) {
            foreach ($item->findings ?? [] as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $reference = $this->referenceResolver->resolve($finding);

                if ($reference->fieldBindingId !== null) {
                    $bindingIds[] = $reference->fieldBindingId;
                }

                if ($reference->variantId !== null) {
                    $variantIds[] = $reference->variantId;
                }
            }
        }

        $bindingsById = $bindingIds === []
            ? []
            : FieldBinding::withoutWorkspaceScope()
                ->with('fieldDefinition')
                ->where(function ($query) use ($workspace): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $workspace->id);
                })
                ->whereIn('id', array_values(array_unique($bindingIds)))
                ->get()
                ->keyBy('id')
                ->all();

        $variantsById = [];

        if ($variantIds !== []) {
            $productIds = $items->pluck('product_id')->unique()->all();

            $variantsById = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereIn('product_id', $productIds)
                ->whereIn('id', array_values(array_unique($variantIds)))
                ->get()
                ->keyBy(fn (ProductVariant $variant): string => (string) $variant->id)
                ->all();
        }

        $historicalMappings = $this->indexMappings($snapshot['field_mappings'] ?? []);
        $currentMappings = $configuration === null
            ? []
            : $this->indexMappings(array_map(
                static fn ($entry): array => [
                    'field_binding_id' => $entry->fieldBindingId,
                    'external_field_key' => $entry->externalFieldKey,
                    'option_mappings' => array_map(
                        static fn ($option): array => $option->toRevisionArray(),
                        $entry->optionMappings,
                    ),
                ],
                $this->mutationCoordinator->effectiveMappingPayload($configuration),
            ));

        return new SyncPreviewPresentationContext(
            actor: $actor,
            workspace: $workspace,
            accountId: $accountId,
            configuration: $configuration,
            configurationSnapshot: $snapshot,
            bindingsById: $bindingsById,
            historicalMappingsByBindingId: $historicalMappings,
            currentMappingsByBindingId: $currentMappings,
            variantsById: $variantsById,
            canManageSetup: $this->authorizationService->canManageSetup($actor, $workspace),
            canViewMappings: $this->authorizationService->canViewMappings($actor, $workspace),
            canManageMappings: $this->authorizationService->canManageMappings($actor, $workspace),
            historicalAttributeSetId: $this->resolveAttributeSetId($snapshot['connector_execution_configuration'] ?? []),
            currentAttributeSetId: $this->resolveCurrentAttributeSetId($configuration),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, array{external_field_key: string, option_mappings: list<array<string, mixed>>}>
     */
    private function indexMappings(array $mappings): array
    {
        $indexed = [];

        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $bindingId = $mapping['field_binding_id'] ?? null;
            $externalKey = $mapping['external_field_key'] ?? null;

            if (! is_string($bindingId) || $bindingId === '' || ! is_string($externalKey)) {
                continue;
            }

            $options = $mapping['option_mappings'] ?? [];

            $indexed[$bindingId] = [
                'external_field_key' => $externalKey,
                'option_mappings' => is_array($options) ? $options : [],
            ];
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveAttributeSetId(array $payload): ?int
    {
        $id = $payload['attribute_set_id'] ?? null;

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    private function resolveCurrentAttributeSetId(?SyncConfiguration $configuration): ?int
    {
        if ($configuration === null) {
            return null;
        }

        try {
            return AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId;
        } catch (\Throwable) {
            return null;
        }
    }
}
