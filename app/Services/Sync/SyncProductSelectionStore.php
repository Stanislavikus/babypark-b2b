<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncConfigurationProductSelection;
use App\Services\Sync\Exceptions\InvalidSyncProductSelectionException;
use App\Support\Sync\SyncProductSelectionDescriptor;
use Illuminate\Support\Str;

final class SyncProductSelectionStore
{
    /** @return list<int> */
    public function selectedProductIds(SyncConfiguration $configuration): array
    {
        return SyncConfigurationProductSelection::withoutWorkspaceScope()
            ->where('workspace_id', $configuration->workspace_id)
            ->where('sync_configuration_id', $configuration->id)
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function descriptorForConfiguration(SyncConfiguration $configuration): SyncProductSelectionDescriptor
    {
        return SyncProductSelectionDescriptor::fromProductIds($this->selectedProductIds($configuration));
    }

    /**
     * Caller must hold the parent SyncConfiguration row lock.
     *
     * @param  iterable<int|string>  $productIds
     */
    public function replaceLocked(SyncConfiguration $configuration, iterable $productIds): SyncProductSelectionDescriptor
    {
        $descriptor = SyncProductSelectionDescriptor::fromProductIds($productIds);
        $this->assertProductsBelongToWorkspace($configuration->workspace_id, $descriptor->productIds);

        if ($descriptor->productIds === $this->selectedProductIds($configuration)) {
            return $descriptor;
        }

        SyncConfigurationProductSelection::withoutWorkspaceScope()
            ->where('workspace_id', $configuration->workspace_id)
            ->where('sync_configuration_id', $configuration->id)
            ->delete();

        $now = now();
        foreach (array_chunk($descriptor->productIds, 500) as $chunk) {
            SyncConfigurationProductSelection::withoutWorkspaceScope()->insert(array_map(
                static fn (int $productId): array => [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $configuration->workspace_id,
                    'sync_configuration_id' => $configuration->id,
                    'product_id' => $productId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $chunk,
            ));
        }

        return $descriptor;
    }

    /** @param list<int> $productIds */
    private function assertProductsBelongToWorkspace(string $workspaceId, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $existing = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missing = array_values(array_diff($productIds, $existing));

        if ($missing !== []) {
            throw InvalidSyncProductSelectionException::productsOutsideWorkspace($missing);
        }
    }
}
