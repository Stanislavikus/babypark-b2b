<?php

namespace App\Services\Pricing;

use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\InvalidCustomerBatchException;
use App\Exceptions\Pricing\InvalidPriceListAssignmentException;
use App\Models\Customer;
use App\Models\PriceList;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerPriceListAssignmentService
{
    public const WORKSPACE_DEFAULT_SENTINEL = '__workspace_default__';

    public function validateTarget(string $workspaceId, ?string $targetPriceListId): ?PriceList
    {
        if ($targetPriceListId === null) {
            return null;
        }

        $priceList = PriceList::withoutWorkspaceScope()->find($targetPriceListId);

        if ($priceList === null) {
            throw InvalidPriceListAssignmentException::notFound();
        }

        if ($priceList->workspace_id !== $workspaceId) {
            throw InvalidPriceListAssignmentException::crossWorkspace();
        }

        if ($priceList->status !== PriceListStatus::Active) {
            throw InvalidPriceListAssignmentException::inactive($priceList->name);
        }

        if ($priceList->is_default) {
            throw InvalidPriceListAssignmentException::workspaceDefault();
        }

        return $priceList;
    }

    /**
     * @param  array<int|string>  $customerIds
     */
    public function preview(string $workspaceId, array $customerIds, ?string $targetPriceListId): AssignmentPreview
    {
        $customers = $this->loadAndValidateCustomers($workspaceId, $customerIds);

        $this->validateTarget($workspaceId, $targetPriceListId);

        return $this->buildPreview($customers, $targetPriceListId);
    }

    /**
     * @param  array<int|string>  $customerIds
     */
    public function apply(string $workspaceId, array $customerIds, ?string $targetPriceListId): AssignmentResult
    {
        $customers = $this->loadAndValidateCustomers($workspaceId, $customerIds);

        $this->validateTarget($workspaceId, $targetPriceListId);

        $preview = $this->buildPreview($customers, $targetPriceListId);

        $updatedCount = 0;

        DB::transaction(function () use ($customers, $targetPriceListId, &$updatedCount): void {
            foreach ($customers as $customer) {
                if ($customer->default_price_list_id === $targetPriceListId) {
                    continue;
                }

                $customer->update(['default_price_list_id' => $targetPriceListId]);
                $updatedCount++;
            }
        });

        return new AssignmentResult(
            selectedCount: $preview->selectedCount,
            updatedCount: $updatedCount,
            unchangedCount: $preview->unchangedCount,
            replacedCount: $preview->replacedCount,
            clearedCount: $preview->clearedCount,
        );
    }

    public function resolveTargetFromSentinel(?string $sentinelOrId): ?string
    {
        if ($sentinelOrId === null || $sentinelOrId === self::WORKSPACE_DEFAULT_SENTINEL) {
            return null;
        }

        return $sentinelOrId;
    }

    /**
     * @return array<string, string>
     */
    public function selectableOptions(string $workspaceId): array
    {
        $options = [
            '' => 'За замовчуванням (використовується основний прайс-лист компанії)',
        ];

        $lists = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', PriceListStatus::Active)
            ->where('is_default', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($lists as $list) {
            $options[$list->id] = $list->name;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function bulkSelectableOptions(string $workspaceId): array
    {
        $options = [
            self::WORKSPACE_DEFAULT_SENTINEL => 'За замовчуванням (використовується основний прайс-лист компанії)',
        ];

        $lists = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', PriceListStatus::Active)
            ->where('is_default', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($lists as $list) {
            $options[$list->id] = $list->name;
        }

        return $options;
    }

    /**
     * @param  array<int|string>  $customerIds
     * @return Collection<int, Customer>
     */
    private function loadAndValidateCustomers(string $workspaceId, array $customerIds): Collection
    {
        $uniqueIds = array_values(array_unique(array_map(
            static fn ($id): string => (string) $id,
            $customerIds,
        )));

        if ($uniqueIds === []) {
            throw InvalidCustomerBatchException::notFound('(empty)');
        }

        $customers = Customer::withoutWorkspaceScope()
            ->whereIn('id', $uniqueIds)
            ->get()
            ->keyBy(static fn (Customer $customer): string => (string) $customer->id);

        foreach ($uniqueIds as $customerId) {
            $customer = $customers->get($customerId);

            if ($customer === null) {
                throw InvalidCustomerBatchException::notFound($customerId);
            }

            if ($customer->workspace_id !== $workspaceId) {
                throw InvalidCustomerBatchException::crossWorkspace($customerId);
            }
        }

        return collect($uniqueIds)
            ->map(static fn (string $id): Customer => $customers->get($id));
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function buildPreview(Collection $customers, ?string $targetPriceListId): AssignmentPreview
    {
        $selectedCount = $customers->count();
        $unchangedCount = 0;
        $replacedCount = 0;
        $clearedCount = 0;

        foreach ($customers as $customer) {
            $current = $customer->default_price_list_id;

            if ($current === $targetPriceListId) {
                $unchangedCount++;

                continue;
            }

            if ($targetPriceListId === null && $current !== null) {
                $clearedCount++;
            }

            if ($targetPriceListId !== null && $current !== null && $current !== $targetPriceListId) {
                $replacedCount++;
            }
        }

        $changedCount = $selectedCount - $unchangedCount;

        return new AssignmentPreview(
            selectedCount: $selectedCount,
            changedCount: $changedCount,
            replacedCount: $replacedCount,
            unchangedCount: $unchangedCount,
            clearedCount: $clearedCount,
        );
    }
}
