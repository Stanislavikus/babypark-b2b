<?php

namespace Tests\Feature\Connectors;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorConnectionCheckBindingTest extends TestCase
{
    #[Test]
    public function application_code_depends_on_capability_interface_not_concrete_impl(): void
    {
        $serviceSource = file_get_contents(app_path('Services/Connectors/AdobePaaSConnectionCheckService.php'));
        $providerSource = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('AdobePaaSConnectionCheckCapability', $serviceSource);
        $this->assertStringNotContainsString('AdobePaaSConnectionCheckCapabilityImpl', $serviceSource);
        $this->assertStringContainsString('AdobePaaSConnectionCheckCapabilityImpl::class', $providerSource);
    }
}
