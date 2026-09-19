<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stage 3E validation allow-host
    |--------------------------------------------------------------------------
    |
    | Exact validation target host supplied outside git. This must match the
    | ConnectorAccount base_url host and the --expect-host runtime argument.
    |
    */
    'allow_host' => env('ADOBE_STAGE3E_VALIDATION_ALLOW_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Validation SKU prefix
    |--------------------------------------------------------------------------
    */
    'sku_prefix' => 'B2BVAL-',

    /*
    |--------------------------------------------------------------------------
    | Validation artifact directory
    |--------------------------------------------------------------------------
    |
    | Artifacts are always written under the local storage disk in this fixed
    | directory. Arbitrary output paths are intentionally unsupported.
    |
    */
    'artifact_directory' => 'stage3e-validation',
];
