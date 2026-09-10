<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorExecutionConfigurationTest extends TestCase
{
    #[Test]
    public function finite_float_values_are_accepted(): void
    {
        $configuration = ConnectorExecutionConfiguration::fromPayload([
            'rate' => 1.25,
        ]);

        $this->assertSame(1.25, $configuration->payload()['rate']);
    }

    #[Test]
    #[DataProvider('nonFiniteFloatProvider')]
    public function non_finite_float_values_are_rejected(float $value): void
    {
        $this->expectException(ConnectorExecutionConfigurationValidationException::class);

        ConnectorExecutionConfiguration::fromPayload([
            'rate' => $value,
        ]);
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function nonFiniteFloatProvider(): array
    {
        return [
            'INF' => [INF],
            '-INF' => [-INF],
            'NAN' => [NAN],
        ];
    }
}
