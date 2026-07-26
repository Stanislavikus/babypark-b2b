<?php

namespace Tests\Feature\Connectors;

use App\Services\Connectors\AdobePaaSConnectionCheckService;
use App\Support\Connectors\AdobePaaS\AdobePaaSConnectionCheckCapability;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContext;
use App\Support\Connectors\ConnectorConnectionCheckResult;
use App\Support\Connectors\Exceptions\ConnectorAccountNotFoundException;
use App\Support\Connectors\Transport\DestinationRequestMismatch;
use App\Support\Connectors\Transport\TransportConfigurationException;
use App\Support\Connectors\Transport\TransportConfigurationFailureReason;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class AdobePaaSConnectionCheckServiceTest extends TestCase
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
    public function resolves_from_container_and_uses_interface_binding(): void
    {
        $service = app(AdobePaaSConnectionCheckService::class);

        $this->assertInstanceOf(AdobePaaSConnectionCheckService::class, $service);
    }

    #[Test]
    public function invokes_capability_once_with_context_from_factory(): void
    {
        $account = $this->createConnectorAccount();
        $spy = new class
        {
            public int $invocations = 0;

            public ?AdobePaaSRequestContext $capturedContext = null;
        };

        $fakeCapability = new class($spy) implements AdobePaaSConnectionCheckCapability
        {
            public function __construct(private object $spy) {}

            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                $this->spy->invocations++;
                $this->spy->capturedContext = $context;

                return ConnectorConnectionCheckResult::success();
            }
        };

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, $fakeCapability);

        $result = app(AdobePaaSConnectionCheckService::class)->execute(
            $account->workspace_id,
            $account->id,
        );

        $this->assertTrue($result->succeeded);
        $this->assertSame(1, $spy->invocations);
        $this->assertNotNull($spy->capturedContext);
        $this->assertSame('https://shop.example.com', $spy->capturedContext->baseUrl);
        $this->assertSame('default', $spy->capturedContext->storeCode);
        $this->assertSame('ck_live', $spy->capturedContext->credentials->consumerKey);
    }

    #[Test]
    public function propagates_context_factory_precondition_failure_without_calling_capability(): void
    {
        $spy = new class
        {
            public bool $capabilityCalled = false;
        };

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, new class($spy) implements AdobePaaSConnectionCheckCapability
        {
            public function __construct(private object $spy) {}

            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                $this->spy->capabilityCalled = true;

                return ConnectorConnectionCheckResult::success();
            }
        });

        $this->expectException(ConnectorAccountNotFoundException::class);

        try {
            app(AdobePaaSConnectionCheckService::class)->execute(
                $this->defaultWorkspace()->id,
                '00000000-0000-4000-8000-000000000000',
            );
        } finally {
            $this->assertFalse($spy->capabilityCalled);
        }
    }

    #[Test]
    public function destination_request_mismatch_propagates_from_capability(): void
    {
        $account = $this->createConnectorAccount();

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, new class implements AdobePaaSConnectionCheckCapability
        {
            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                throw new DestinationRequestMismatch;
            }
        });

        $this->expectException(DestinationRequestMismatch::class);

        app(AdobePaaSConnectionCheckService::class)->execute($account->workspace_id, $account->id);
    }

    #[Test]
    public function transport_configuration_exception_propagates_from_capability(): void
    {
        $account = $this->createConnectorAccount();

        $this->app->instance(AdobePaaSConnectionCheckCapability::class, new class implements AdobePaaSConnectionCheckCapability
        {
            public function checkConnection(AdobePaaSRequestContext $context): ConnectorConnectionCheckResult
            {
                throw new TransportConfigurationException(TransportConfigurationFailureReason::CurlUnavailable);
            }
        });

        $this->expectException(TransportConfigurationException::class);

        app(AdobePaaSConnectionCheckService::class)->execute($account->workspace_id, $account->id);
    }

    #[Test]
    public function adobe_profile_advertises_connection_check_capability(): void
    {
        $this->assertSame(['connection_check'], config('connectors.profiles.adobe_commerce_paas_oauth1_integration.capabilities'));
    }
}
