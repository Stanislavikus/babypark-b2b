<?php

namespace App\Services\Availability;

use App\Enums\InventoryRecordSourceType;
use App\Enums\ReservationStatus;
use App\Exceptions\Availability\InvalidReservationTransitionException;
use App\Models\InventoryRecord;
use App\Models\ProductVariant;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationConfirmer
{
    public function confirm(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ReservationStatus::Confirmed) {
                return;
            }

            if (in_array($locked->status, [ReservationStatus::Cancelled, ReservationStatus::Expired], true)) {
                throw new InvalidReservationTransitionException(
                    "Cannot confirm reservation {$locked->id} in status {$locked->status->value}."
                );
            }

            $variant = ProductVariant::query()
                ->whereKey($locked->variant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update(['status' => ReservationStatus::Confirmed]);

            $newCache = max(0, (int) $variant->available_quantity_cache - (int) $locked->quantity);

            $variant->update([
                'available_quantity_cache' => $newCache,
                'availability_status' => $newCache > 0 ? 'in_stock' : 'out_of_stock',
            ]);

            InventoryRecord::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $variant->workspace_id,
                'product_variant_id' => $variant->id,
                'inventory_location_id' => null,
                'location_name_snapshot' => null,
                'source_type' => InventoryRecordSourceType::OrderAllocation,
                'source_reference_id' => $locked->order_id ? (string) $locked->order_id : null,
                'quantity_change' => -1 * (int) $locked->quantity,
                'resulting_quantity' => $newCache,
                'reason' => 'Reservation confirmed',
            ]);
        }, 3);
    }
}
