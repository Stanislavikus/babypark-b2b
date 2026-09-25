<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Availability\ReservationReleaser;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Release pending reservations that have passed their TTL';

    public function handle(ReservationReleaser $releaser): int
    {
        $query = Reservation::withoutWorkspaceScope()
            ->where('status', ReservationStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        $count = 0;

        $query->orderBy('id')->each(function (Reservation $reservation) use ($releaser, &$count): void {
            $releaser->release($reservation, 'expired');
            $count++;
        });

        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
