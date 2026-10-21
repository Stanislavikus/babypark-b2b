<?php

namespace App\Services\Connectors;

use App\Models\AdobeProductAttributeGroup;
use App\Models\AdobeProductAttributeLineage;
use App\Models\AdobeProductAttributeOptionLineage;
use App\Models\AdobeProductAttributeSet;
use App\Models\AdobeProductAttributeSetMembership;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\AdobePaaS\AdobeProductAttributeStructureReader;
use App\Support\Connectors\AdobePaaS\AdobeProductAttributeStructureReconcileResult;
use App\Support\Connectors\AdobePaaS\AdobeProductAttributeStructureSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdobeProductAttributeStructureReconciler
{
    public function __construct(
        private readonly ConnectorDiscoverySourceResolver $sourceResolver,
        private readonly AdobeProductAttributeStructureReader $reader,
    ) {}

    public function reconcile(string $workspaceId, string $connectorAccountId): AdobeProductAttributeStructureReconcileResult
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereKey($connectorAccountId)
            ->firstOrFail();
        $source = $this->sourceResolver->resolve($account);
        $targetSignature = $this->targetSignature($account);
        $sourceEndpointPath = $source->endpoint_path;
        $snapshot = $this->reader->read($workspaceId, $connectorAccountId, $sourceEndpointPath);

        return DB::transaction(function () use ($workspaceId, $connectorAccountId, $source, $sourceEndpointPath, $targetSignature, $snapshot): AdobeProductAttributeStructureReconcileResult {
            $lockedAccount = ConnectorAccount::withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereKey($connectorAccountId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSource = ConnectorSchemaSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            if ($this->targetSignature($lockedAccount) !== $targetSignature
                || $lockedSource->endpoint_path !== $sourceEndpointPath
                || ! $this->sourceResolver->reverify($lockedAccount, $lockedSource)) {
                throw new \RuntimeException('Adobe product structure target changed during reconciliation.');
            }

            $this->persistSnapshot($lockedAccount, $lockedSource, $snapshot);

            return new AdobeProductAttributeStructureReconcileResult(
                capturedAt: $snapshot->capturedAt,
                attributes: count($snapshot->attributes),
                attributeSets: count($snapshot->attributeSets),
                attributeGroups: count($snapshot->attributeGroups),
                setMemberships: count($snapshot->setMemberships),
                options: count($snapshot->options),
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    private function targetSignature(ConnectorAccount $account): array
    {
        return [
            'connector_definition_id' => $account->connector_definition_id,
            'auth_profile' => $account->auth_profile,
            'base_url' => $account->base_url,
            'store_code' => $account->store_code,
            'tenant_context' => $account->tenant_context,
            'is_enabled' => $account->is_enabled,
            'credentials' => $account->credentials,
        ];
    }

    private function persistSnapshot(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        AdobeProductAttributeStructureSnapshot $snapshot,
    ): void {
        $scope = [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
        ];
        $capturedAt = $snapshot->capturedAt;

        $existingLineages = AdobeProductAttributeLineage::withoutWorkspaceScope()
            ->where($scope)
            ->get()
            ->keyBy('provider_attribute_id');
        AdobeProductAttributeLineage::withoutWorkspaceScope()->where($scope)->whereNull('missing_since')->update(['missing_since' => $capturedAt]);

        $lineageRows = [];
        $lineageIds = [];

        foreach ($snapshot->attributes as $attribute) {
            $providerId = $attribute['provider_attribute_id'];
            $existing = $existingLineages->get($providerId);
            $id = $existing?->id ?? (string) Str::uuid();
            $lineageIds[$providerId] = $id;
            $lineageRows[] = [
                ...$scope,
                'id' => $id,
                'provider_attribute_id' => $providerId,
                'last_external_field_key' => $attribute['external_field_key'],
                'first_seen_at' => $existing?->first_seen_at ?? $capturedAt,
                'last_seen_at' => $capturedAt,
                'missing_since' => null,
                'created_at' => $existing?->created_at ?? $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        $this->upsertRows(
            'adobe_product_attribute_lineages',
            $lineageRows,
            ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'provider_attribute_id'],
            ['last_external_field_key', 'last_seen_at', 'missing_since', 'updated_at'],
        );

        $existingSets = AdobeProductAttributeSet::withoutWorkspaceScope()
            ->where($scope)
            ->get()
            ->keyBy('provider_attribute_set_id');
        AdobeProductAttributeSet::withoutWorkspaceScope()->where($scope)->whereNull('missing_since')->update(['missing_since' => $capturedAt]);

        $setRows = [];
        $setIds = [];

        foreach ($snapshot->attributeSets as $attributeSet) {
            $providerId = $attributeSet['provider_attribute_set_id'];
            $existing = $existingSets->get($providerId);
            $id = $existing?->id ?? (string) Str::uuid();
            $setIds[$providerId] = $id;
            $setRows[] = [
                ...$scope,
                'id' => $id,
                'provider_attribute_set_id' => $providerId,
                'name' => $attributeSet['name'],
                'first_seen_at' => $existing?->first_seen_at ?? $capturedAt,
                'last_seen_at' => $capturedAt,
                'missing_since' => null,
                'created_at' => $existing?->created_at ?? $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        $this->upsertRows(
            'adobe_product_attribute_sets',
            $setRows,
            ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'provider_attribute_set_id'],
            ['name', 'last_seen_at', 'missing_since', 'updated_at'],
        );

        $this->persistGroups($scope, $snapshot, $setIds, $capturedAt);
        $this->persistMemberships($scope, $snapshot, $lineageIds, $setIds, $capturedAt);
        $this->persistOptions($scope, $snapshot, $lineageIds, $capturedAt);
    }

    /**
     * @param  array<string, string>  $scope
     * @param  array<int, string>  $setIds
     */
    private function persistGroups(array $scope, AdobeProductAttributeStructureSnapshot $snapshot, array $setIds, mixed $capturedAt): void
    {
        $existingRows = AdobeProductAttributeGroup::withoutWorkspaceScope()
            ->where($scope)
            ->get()
            ->keyBy('provider_attribute_group_id');
        AdobeProductAttributeGroup::withoutWorkspaceScope()->where($scope)->whereNull('missing_since')->update(['missing_since' => $capturedAt]);
        $rows = [];

        foreach ($snapshot->attributeGroups as $group) {
            $providerId = $group['provider_attribute_group_id'];
            $existing = $existingRows->get($providerId);
            $setId = $setIds[$group['provider_attribute_set_id']] ?? null;

            if ($setId === null) {
                throw new \LogicException('Attribute group referenced an unknown Product Attribute Set.');
            }

            $rows[] = [
                ...$scope,
                'id' => $existing?->id ?? (string) Str::uuid(),
                'adobe_product_attribute_set_id' => $setId,
                'provider_attribute_group_id' => $providerId,
                'name' => $group['name'],
                'first_seen_at' => $existing?->first_seen_at ?? $capturedAt,
                'last_seen_at' => $capturedAt,
                'missing_since' => null,
                'created_at' => $existing?->created_at ?? $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        $this->upsertRows(
            'adobe_product_attribute_groups',
            $rows,
            ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'provider_attribute_group_id'],
            ['adobe_product_attribute_set_id', 'name', 'last_seen_at', 'missing_since', 'updated_at'],
        );
    }

    /**
     * @param  array<string, string>  $scope
     * @param  array<int, string>  $lineageIds
     * @param  array<int, string>  $setIds
     */
    private function persistMemberships(
        array $scope,
        AdobeProductAttributeStructureSnapshot $snapshot,
        array $lineageIds,
        array $setIds,
        mixed $capturedAt,
    ): void {
        $existingRows = AdobeProductAttributeSetMembership::withoutWorkspaceScope()
            ->where($scope)
            ->get()
            ->keyBy(fn (AdobeProductAttributeSetMembership $row): string => $row->adobe_product_attribute_lineage_id.':'.$row->adobe_product_attribute_set_id);
        AdobeProductAttributeSetMembership::withoutWorkspaceScope()->where($scope)->whereNull('missing_since')->update(['missing_since' => $capturedAt]);
        $rows = [];

        foreach ($snapshot->setMemberships as $membership) {
            $lineageId = $lineageIds[$membership['provider_attribute_id']] ?? null;
            $setId = $setIds[$membership['provider_attribute_set_id']] ?? null;

            if ($lineageId === null || $setId === null) {
                throw new \LogicException('Attribute Set membership referenced unknown provider identity.');
            }

            $existing = $existingRows->get($lineageId.':'.$setId);
            $rows[] = [
                ...$scope,
                'id' => $existing?->id ?? (string) Str::uuid(),
                'adobe_product_attribute_lineage_id' => $lineageId,
                'adobe_product_attribute_set_id' => $setId,
                'first_seen_at' => $existing?->first_seen_at ?? $capturedAt,
                'last_seen_at' => $capturedAt,
                'missing_since' => null,
                'created_at' => $existing?->created_at ?? $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        $this->upsertRows(
            'adobe_product_attribute_set_memberships',
            $rows,
            ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'adobe_product_attribute_lineage_id', 'adobe_product_attribute_set_id'],
            ['last_seen_at', 'missing_since', 'updated_at'],
        );
    }

    /**
     * @param  array<string, string>  $scope
     * @param  array<int, string>  $lineageIds
     */
    private function persistOptions(
        array $scope,
        AdobeProductAttributeStructureSnapshot $snapshot,
        array $lineageIds,
        mixed $capturedAt,
    ): void {
        $existingRows = AdobeProductAttributeOptionLineage::withoutWorkspaceScope()
            ->where($scope)
            ->get()
            ->keyBy(fn (AdobeProductAttributeOptionLineage $row): string => $row->adobe_product_attribute_lineage_id.':'.$row->provider_option_id);
        AdobeProductAttributeOptionLineage::withoutWorkspaceScope()->where($scope)->whereNull('missing_since')->update(['missing_since' => $capturedAt]);
        $rows = [];

        foreach ($snapshot->options as $option) {
            $lineageId = $lineageIds[$option['provider_attribute_id']] ?? null;

            if ($lineageId === null) {
                throw new \LogicException('Attribute option referenced an unknown provider attribute identity.');
            }

            $existing = $existingRows->get($lineageId.':'.$option['provider_option_id']);
            $labelsByStore = is_array($existing?->labels_by_store) ? $existing->labels_by_store : [];
            $labelsByStore[$snapshot->storeCode] = $option['label'];
            $defaultLabel = $snapshot->storeCode === 'default'
                ? $option['label']
                : $existing?->default_label;

            $rows[] = [
                ...$scope,
                'id' => $existing?->id ?? (string) Str::uuid(),
                'adobe_product_attribute_lineage_id' => $lineageId,
                'provider_option_id' => $option['provider_option_id'],
                'default_label' => $defaultLabel,
                'labels_by_store' => json_encode($labelsByStore, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'first_seen_at' => $existing?->first_seen_at ?? $capturedAt,
                'last_seen_at' => $capturedAt,
                'missing_since' => null,
                'created_at' => $existing?->created_at ?? $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        $this->upsertRows(
            'adobe_product_attribute_option_lineages',
            $rows,
            ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'adobe_product_attribute_lineage_id', 'provider_option_id'],
            ['default_label', 'labels_by_store', 'last_seen_at', 'missing_since', 'updated_at'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $updateColumns
     */
    private function upsertRows(string $table, array $rows, array $uniqueBy, array $updateColumns): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }
}
