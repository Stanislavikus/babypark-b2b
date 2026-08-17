<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\ConnectorExecutionConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
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
    public function non_finite_float_values_are_rejected(): void
    {
        foreach ([INF, -INF, NAN] as $value) {
            try {
                ConnectorExecutionConfiguration::fromPayload(['rate' => $value]);
                $this->fail('Expected invalid payload exception for non-finite float.');
            } catch (ConnectorExecutionConfigurationValidationException) {
                // expected
            }
        }
    }
}
