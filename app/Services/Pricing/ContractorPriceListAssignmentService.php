<?php

namespace App\Services\Pricing;

use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\InvalidContractorBatchException;
use App\Exceptions\Pricing\InvalidPriceListAssignmentException;
use App\Models\Contractor;
use App\Models\PriceList;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractorPriceListAssignmentService
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
     * @param  array<int|string>  $contractorIds
     */
    public function preview(string $workspaceId, array $contractorIds, ?string $targetPriceListId): AssignmentPreview
    {
        $contractors = $this->loadAndValidateContractors($workspaceId, $contractorIds);

        $this->validateTarget($workspaceId, $targetPriceListId);

        return $this->buildPreview($contractors, $targetPriceListId);
    }

    /**
     * @param  array<int|string>  $contractorIds
     */
    public function apply(string $workspaceId, array $contractorIds, ?string $targetPriceListId): AssignmentResult
    {
        $contractors = $this->loadAndValidateContractors($workspaceId, $contractorIds);

        $this->validateTarget($workspaceId, $targetPriceListId);

        $preview = $this->buildPreview($contractors, $targetPriceListId);

        $updatedCount = 0;

        DB::transaction(function () use ($contractors, $targetPriceListId, &$updatedCount): void {
            foreach ($contractors as $contractor) {
                if ($contractor->default_price_list_id === $targetPriceListId) {
                    continue;
                }

                $contractor->update(['default_price_list_id' => $targetPriceListId]);
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
     * @param  array<int|string>  $contractorIds
     * @return Collection<int, Contractor>
     */
    private function loadAndValidateContractors(string $workspaceId, array $contractorIds): Collection
    {
        $uniqueIds = array_values(array_unique(array_map(
            static fn ($id): string => (string) $id,
            $contractorIds,
        )));

        if ($uniqueIds === []) {
            throw InvalidContractorBatchException::notFound('(empty)');
        }

        $contractors = Contractor::withoutWorkspaceScope()
            ->whereIn('id', $uniqueIds)
            ->get()
            ->keyBy(static fn (Contractor $contractor): string => (string) $contractor->id);

        foreach ($uniqueIds as $contractorId) {
            $contractor = $contractors->get($contractorId);

            if ($contractor === null) {
                throw InvalidContractorBatchException::notFound($contractorId);
            }

            if ($contractor->workspace_id !== $workspaceId) {
                throw InvalidContractorBatchException::crossWorkspace($contractorId);
            }
        }

        return collect($uniqueIds)
            ->map(static fn (string $id): Contractor => $contractors->get($id));
    }

    /**
     * @param  Collection<int, Contractor>  $contractors
     */
    private function buildPreview(Collection $contractors, ?string $targetPriceListId): AssignmentPreview
    {
        $selectedCount = $contractors->count();
        $unchangedCount = 0;
        $replacedCount = 0;
        $clearedCount = 0;

        foreach ($contractors as $contractor) {
            $current = $contractor->default_price_list_id;

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
