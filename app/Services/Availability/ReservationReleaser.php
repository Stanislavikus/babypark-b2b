<?php

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Exceptions\Availability\InvalidReservationTransitionException;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class ReservationReleaser
{
    public function release(Reservation $reservation, string $toStatus = 'cancelled'): void
    {
        $targetStatus = ReservationStatus::from($toStatus);

        if (! in_array($targetStatus, [ReservationStatus::Cancelled, ReservationStatus::Expired], true)) {
            throw new InvalidReservationTransitionException("Invalid release target status: {$toStatus}");
        }

        DB::transaction(function () use ($reservation, $targetStatus): void {
            $locked = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [ReservationStatus::Cancelled, ReservationStatus::Expired], true)) {
                return;
            }

            if ($locked->status === ReservationStatus::Confirmed) {
                throw new InvalidReservationTransitionException(
                    "Cannot release confirmed reservation {$locked->id}."
                );
            }

            $locked->update(['status' => $targetStatus]);
        }, 3);
    }
}
