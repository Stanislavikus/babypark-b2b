<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Services\Sync\Exceptions\InvalidSyncProductSelectionException;
use App\Support\Sync\SyncProductSelectionDescriptor;

final class SyncProductSelectionService
{
    public function __construct(
        private readonly SyncConfigurationMutationCoordinator $mutationCoordinator,
        private readonly SyncProductSelectionStore $selectionStore,
    ) {}

    /** @return list<int> */
    public function selectedProductIds(SyncConfiguration $configuration): array
    {
        $this->assertProductsDomain($configuration);

        return $this->selectionStore->selectedProductIds($configuration);
    }

    /** @param iterable<int|string> $productIds */
    public function replace(ConnectorAccount $account, string $syncConfigurationId, iterable $productIds): SyncConfiguration
    {
        $nextIds = is_array($productIds) ? $productIds : iterator_to_array($productIds, false);

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use ($nextIds): void {
                $this->assertProductsDomain($configuration);
                $this->selectionStore->replaceLocked($configuration, $nextIds);
            },
        );
    }

    /** @param iterable<int|string> $productIds */
    public function add(ConnectorAccount $account, string $syncConfigurationId, iterable $productIds): SyncConfiguration
    {
        return $this->mutateSet($account, $syncConfigurationId, $productIds, true);
    }

    /** @param iterable<int|string> $productIds */
    public function remove(ConnectorAccount $account, string $syncConfigurationId, iterable $productIds): SyncConfiguration
    {
        return $this->mutateSet($account, $syncConfigurationId, $productIds, false);
    }

    /** @param iterable<int|string> $productIds */
    private function mutateSet(
        ConnectorAccount $account,
        string $syncConfigurationId,
        iterable $productIds,
        bool $adding,
    ): SyncConfiguration {
        $requested = is_array($productIds) ? $productIds : iterator_to_array($productIds, false);

        return $this->mutationCoordinator->mutateLocked(
            $account,
            $syncConfigurationId,
            function (SyncConfiguration $configuration) use ($requested, $adding): void {
                $this->assertProductsDomain($configuration);

                $current = $this->selectionStore->selectedProductIds($configuration);
                $requestedDescriptor = SyncProductSelectionDescriptor::fromProductIds($requested);

                $next = $adding
                    ? array_merge($current, $requestedDescriptor->productIds)
                    : array_values(array_diff($current, $requestedDescriptor->productIds));

                $this->selectionStore->replaceLocked($configuration, $next);
            },
        );
    }

    private function assertProductsDomain(SyncConfiguration $configuration): void
    {
        if ($configuration->data_domain !== SyncDataDomain::Products) {
            throw InvalidSyncProductSelectionException::unsupportedDomain();
        }
    }
}
