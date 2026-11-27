<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Default time-to-live for pending soft reservations created during checkout.
    |
    */

    'reservation_ttl_minutes' => (int) env('AVAILABILITY_RESERVATION_TTL_MINUTES', 15),

];
