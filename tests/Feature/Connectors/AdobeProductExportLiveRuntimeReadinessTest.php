<?php

namespace Tests\Feature\Connectors;

use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\AdobePaaS\AdobeProductExportLiveRuntimeReadiness;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\Transport\DestinationRequestMismatch;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

final class AdobeProductExportLiveRuntimeReadinessTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function destination_mismatch_is_not_ready_instead_of_escaping(): void
    {
        $account = $this->createConnectorAccount();

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, new class implements AdobePaaSConnectionCheckCapability
        {
            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                throw new DestinationRequestMismatch;
            }
        });

        $this->assertFalse(
            app(AdobeProductExportLiveRuntimeReadiness::class)
                ->isReady($account->workspace_id, $account->id),
        );
    }

    #[Test]
    public function transport_configuration_failure_is_not_ready_instead_of_escaping(): void
    {
        $account = $this->createConnectorAccount();

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, new class implements AdobePaaSConnectionCheckCapability
        {
            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                throw new TransportConfigurationException(TransportConfigurationFailureReason::CurlUnavailable);
            }
        });

        $this->assertFalse(
            app(AdobeProductExportLiveRuntimeReadiness::class)
                ->isReady($account->workspace_id, $account->id),
        );
    }
}
