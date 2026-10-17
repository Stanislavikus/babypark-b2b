<?php

namespace Tests\Unit\Connectors\Transport;

use App\Support\Connectors\Transport\ConnectorTransportLimits;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorTransportLimitsTest extends TestCase
{
    #[Test]
    public function rejects_invalid_limits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConnectorTransportLimits(0, 5, 1024);
    }

    #[Test]
    public function rejects_total_timeout_less_than_connect_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConnectorTransportLimits(5, 1, 1024);
    }

    #[Test]
    public function rejects_non_positive_max_body(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConnectorTransportLimits(1, 2, 0);
    }
}
