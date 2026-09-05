<?php

namespace App\Support\Connectors\Transport\Curl;

use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;

final class DefaultCurlClientFactory implements CurlClientFactory
{
    /**
     * @param  array<string, mixed>  $defaultOptions
     */
    public function create(array $defaultOptions): Client
    {
        if (! $this->isCurlAvailable()) {
            throw new TransportConfigurationException(TransportConfigurationFailureReason::CurlUnavailable);
        }

        $stack = HandlerStack::create(new CurlHandler);

        return new Client(array_merge(['handler' => $stack], $defaultOptions));
    }

    public function isCurlAvailable(): bool
    {
        return extension_loaded('curl');
    }
}
