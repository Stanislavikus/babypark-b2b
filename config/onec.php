<?php

return [
    'base_url' => env('ONEC_BASE_URL', 'https://1c.babypark.ua/api/v1'),
    'token' => env('ONEC_TOKEN', ''),
    'timeout' => env('ONEC_TIMEOUT', 30),
    'retry_times' => env('ONEC_RETRY_TIMES', 3),
    'retry_sleep_ms' => env('ONEC_RETRY_SLEEP_MS', 500),
];
