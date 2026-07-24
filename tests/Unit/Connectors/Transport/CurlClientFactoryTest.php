<?php

namespace Tests\Unit\Connectors\Transport;

use App\Support\Connectors\Transport\Curl\CurlClientFactory;
use App\Support\Connectors\Transport\Internal\ConnectorRequestSenderImpl;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CurlClientFactoryTest extends TestCase
{
    #[Test]
    public function fails_closed_when_curl_is_unavailable(): void
    {
        $factory = new class implements CurlClientFactory
        {
            public function create(array $defaultOptions): Client
            {
                throw new TransportConfigurationException(TransportConfigurationFailureReason::CurlUnavailable);
            }

            public function isCurlAvailable(): bool
            {
                return false;
            }
        };

        $this->expectException(TransportConfigurationException::class);

        new ConnectorRequestSenderImpl($factory, true);
    }
}
