<?php

namespace App\Services\Availability;

use App\Enums\InventoryRecordSourceType;
use App\Enums\ReservationStatus;
use App\Exceptions\Availability\InsufficientAvailabilityException;
use App\Exceptions\Availability\InvalidReservationTransitionException;
use App\Models\InventoryRecord;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationConfirmer
{
    public function __construct(
        private readonly AvailabilityStatusProjector $statusProjector,
    ) {}

    public function confirm(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $snapshot = Reservation::query()
                ->whereKey($reservation->id)
                ->firstOrFail();

            $variantId = (int) $snapshot->variant_id;

            $variant = ProductVariant::query()
                ->whereKey($variantId)
                ->lockForUpdate()
                ->firstOrFail();

            $stocks = Stock::query()
                ->where('variant_id', $variant->id)
                ->orderBy('inventory_location_id')
                ->lockForUpdate()
                ->get();

            $locked = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->variant_id !== $variantId) {
                throw new InvalidReservationTransitionException(
                    "Reservation {$locked->id} changed variant during confirmation."
                );
            }

            if ($locked->status === ReservationStatus::Confirmed) {
                return;
            }

            if (in_array($locked->status, [ReservationStatus::Cancelled, ReservationStatus::Expired], true)) {
                throw new InvalidReservationTransitionException(
                    "Cannot confirm reservation {$locked->id} in status {$locked->status->value}."
                );
            }

            if ($locked->expires_at !== null && $locked->expires_at->lte(now())) {
                throw new InvalidReservationTransitionException(
                    "Cannot confirm expired reservation {$locked->id}."
                );
            }

            $currentCache = (int) $variant->available_quantity_cache;
            $quantity = (int) $locked->quantity;

            if ($currentCache < $quantity) {
                throw new InsufficientAvailabilityException(
                    "Insufficient allocatable balance for reservation {$locked->id}: requested {$quantity}, available {$currentCache}."
                );
            }

            $newCache = $currentCache - $quantity;
            $inventoryLocationId = null;
            $locationNameSnapshot = null;

            if ($stocks->count() === 1) {
                /** @var Stock $stock */
                $stock = $stocks->sole();

                if ((int) $stock->quantity === $currentCache) {
                    $stock->update(['quantity' => $newCache]);
                    $inventoryLocationId = $stock->inventory_location_id;
                    $locationNameSnapshot = $stock->inventoryLocation()
                        ->withoutGlobalScopes()
                        ->value('name');
                }
            }

            $locked->update(['status' => ReservationStatus::Confirmed]);

            $variant->update([
                'available_quantity_cache' => $newCache,
                'availability_status' => $this->statusProjector->fromAllocatableBalance($newCache),
            ]);

            InventoryRecord::withoutWorkspaceScope()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $variant->workspace_id,
                'product_variant_id' => $variant->id,
                'inventory_location_id' => $inventoryLocationId,
                'location_name_snapshot' => $locationNameSnapshot,
                'source_type' => InventoryRecordSourceType::OrderAllocation,
                'source_reference_id' => $locked->order_id ? (string) $locked->order_id : null,
                'quantity_change' => -1 * $quantity,
                'resulting_quantity' => $newCache,
                'reason' => 'Reservation confirmed',
            ]);
        }, 3);
    }
}
