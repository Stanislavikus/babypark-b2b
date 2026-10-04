<?php

namespace App\Services\Availability;

use App\Enums\InventoryRecordSourceType;
use App\Exceptions\Availability\InventoryMutationException;
use App\Models\InventoryLocation;
use App\Models\InventoryRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MasterInventoryMutationService
{
    private const INTERNAL_DEFAULT_LOCATION_NAME = 'Основна локація';

    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly AvailabilityStatusProjector $statusProjector,
    ) {}

    public function setQuantity(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductVariant $variant,
        int $expectedQuantity,
        int $newQuantity,
        ?string $reason = null,
    ): Stock {
        if ($expectedQuantity < 0 || $newQuantity < 0) {
            throw InventoryMutationException::invalidQuantity();
        }

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $product,
            $variant,
            $expectedQuantity,
            $newQuantity,
            $reason,
        ): Stock {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVariant = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedVariant instanceof ProductVariant) {
                throw InventoryMutationException::variantUnavailable();
            }

            $stocks = $this->lockedStocks($lockedVariant);

            if ($stocks->count() > 1) {
                throw InventoryMutationException::multipleLocationsReadOnly();
            }

            if ($stocks->isEmpty()) {
                if ((int) $lockedVariant->available_quantity_cache !== 0) {
                    throw InventoryMutationException::reconciliationRequired();
                }

                $location = $this->resolveDefaultLocation($lockedWorkspace);

                Stock::withoutWorkspaceScope()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'variant_id' => $lockedVariant->id,
                    'inventory_location_id' => $location->id,
                    'quantity' => 0,
                    'expected_date' => null,
                    'expected_quantity' => null,
                ]);

                $stocks = $this->lockedStocks($lockedVariant);
            }

            /** @var Stock $stock */
            $stock = $stocks->sole();
            $currentStock = (int) $stock->quantity;
            $currentCache = (int) $lockedVariant->available_quantity_cache;

            if ($currentStock !== $currentCache) {
                throw InventoryMutationException::reconciliationRequired();
            }

            if ($currentStock !== $expectedQuantity) {
                throw InventoryMutationException::staleQuantity();
            }

            if ($newQuantity === $currentStock) {
                return $stock;
            }

            $location = InventoryLocation::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($stock->inventory_location_id)
                ->firstOrFail();

            $delta = $newQuantity - $currentStock;

            $stock->update([
                'quantity' => $newQuantity,
            ]);

            $lockedVariant->update([
                'available_quantity_cache' => $newQuantity,
                'availability_status' => $this->statusProjector->fromAllocatableBalance($newQuantity),
            ]);

            InventoryRecord::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $lockedWorkspace->id,
                'product_variant_id' => $lockedVariant->id,
                'inventory_location_id' => $location->id,
                'location_name_snapshot' => $location->name,
                'source_type' => InventoryRecordSourceType::ManualAdjustment,
                'source_reference_id' => null,
                'quantity_change' => $delta,
                'resulting_quantity' => $newQuantity,
                'reason' => filled($reason) ? trim((string) $reason) : null,
            ]);

            return $stock->refresh();
        }, 3);
    }

    /**
     * @return Collection<int, Stock>
     */
    private function lockedStocks(ProductVariant $variant): Collection
    {
        return Stock::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('variant_id', $variant->id)
            ->orderBy('inventory_location_id')
            ->lockForUpdate()
            ->get();
    }

    private function resolveDefaultLocation(Workspace $workspace): InventoryLocation
    {
        /** @var Collection<int, InventoryLocation> $locations */
        $locations = InventoryLocation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($locations->isEmpty()) {
            return InventoryLocation::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'name' => self::INTERNAL_DEFAULT_LOCATION_NAME,
                'type' => 'warehouse',
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $active = $locations->where('is_active', true)->values();

        if ($active->count() === 1) {
            /** @var InventoryLocation $location */
            $location = $active->sole();

            return $location;
        }

        if ($active->count() > 1) {
            $defaults = $active->where('is_default', true)->values();

            if ($defaults->count() === 1) {
                /** @var InventoryLocation $location */
                $location = $defaults->sole();

                return $location;
            }
        }

        throw InventoryMutationException::defaultLocationUnavailable();
    }
}
