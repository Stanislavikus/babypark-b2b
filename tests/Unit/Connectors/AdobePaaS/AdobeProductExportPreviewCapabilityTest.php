<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductExportPreviewCapabilityTest extends TestCase
{
    #[Test]
    public function strict_adobe_execution_configuration_rejects_unknown_keys(): void
    {
        $this->expectException(ConnectorExecutionConfigurationValidationException::class);

        AdobeProductExportExecutionConfiguration::fromPayload([
            'attribute_set_id' => 4,
            'extra' => 'value',
        ]);
    }

    #[Test]
    public function strict_adobe_execution_configuration_accepts_only_attribute_set_id(): void
    {
        $configuration = AdobeProductExportExecutionConfiguration::fromPayload([
            'attribute_set_id' => 9,
        ]);

        $this->assertSame(9, $configuration->attributeSetId);
        $this->assertSame(['attribute_set_id' => 9], $configuration->toPayload());
    }
}
