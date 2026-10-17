<?php

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Exceptions\Availability\InsufficientAvailabilityException;
use App\Exceptions\Availability\InvalidReservationQuantityException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class ReservationCreator
{
    public function __construct(
        private readonly AvailabilityResolver $availabilityResolver,
    ) {}

    public function create(
        ProductVariant $variant,
        int $quantity,
        ?Order $order = null,
        ?Customer $customer = null,
        ?int $ttlMinutes = null,
    ): Reservation {
        if ($quantity <= 0) {
            throw new InvalidReservationQuantityException('Reservation quantity must be greater than zero.');
        }

        $customerId = $order?->customer_id ?? $customer?->id;

        if ($customerId === null) {
            throw new InvalidReservationQuantityException('A customer is required to create a reservation.');
        }

        $ttlMinutes = $ttlMinutes ?? (int) config('availability.reservation_ttl_minutes', 15);

        return DB::transaction(function () use ($variant, $quantity, $order, $customerId, $ttlMinutes): Reservation {
            $lockedVariant = ProductVariant::query()
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            Reservation::query()
                ->where('variant_id', $lockedVariant->id)
                ->where('status', ReservationStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $netAvailable = $this->availabilityResolver->netAvailable($lockedVariant);

            if ($netAvailable < $quantity) {
                throw new InsufficientAvailabilityException(
                    "Insufficient availability for variant {$lockedVariant->id}: requested {$quantity}, available {$netAvailable}."
                );
            }

            return Reservation::query()->create([
                'workspace_id' => $lockedVariant->workspace_id,
                'customer_id' => $customerId,
                'order_id' => $order?->id,
                'variant_id' => $lockedVariant->id,
                'quantity' => $quantity,
                'status' => ReservationStatus::Pending,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        }, 3);
    }
}
