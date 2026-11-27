<?php

namespace App\Support\Connectors\Transport\Curl;

use GuzzleHttp\Client;

interface CurlClientFactory
{
    /**
     * @param  array<string, mixed>  $defaultOptions
     */
    public function create(array $defaultOptions): Client;

    public function isCurlAvailable(): bool;
}
