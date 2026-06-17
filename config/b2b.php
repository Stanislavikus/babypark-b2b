<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reservation TTL
    |--------------------------------------------------------------------------
    | How many hours a new reservation stays active before it expires.
    */
    'reservation_ttl_hours' => env('B2B_RESERVATION_TTL_HOURS', 48),
];
