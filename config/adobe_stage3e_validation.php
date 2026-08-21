<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stage 3E validation allow-host
    |--------------------------------------------------------------------------
    |
    | Exact validation target host supplied outside git (environment variable).
    | Must match ConnectorAccount base_url host and --expect-host at runtime.
    |
    */
    'allow_host' => env('ADOBE_STAGE3E_VALIDATION_ALLOW_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Validation SKU prefix
    |--------------------------------------------------------------------------
    */
    'sku_prefix' => 'B2BVAL-',
];
