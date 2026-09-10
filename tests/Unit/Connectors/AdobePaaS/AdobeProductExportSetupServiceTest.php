<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Services\Sync\AdobeProductExportSetupService;
use App\Services\Sync\SyncConfigurationReachabilityService;
use App\Services\Sync\SyncConfigurationService;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionConfiguration;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataReader;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataRequestFactory;
use App\Support\Connectors\AdobePaaS\Exceptions\AdobeProductExportSetupRequiredException;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductExportSetupServiceTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function configure_attribute_set_persists_validated_selection(): void
    {
        $account = $this->createConnectorAccount();
        $service = $this->serviceWithTransport(new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request): ConnectorHttpResult {
                $uri = (string) $request->request->getUri();

                if (str_contains($uri, '/attribute-sets/sets/list')) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'items' => [
                            ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                            ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                        ],
                    ], JSON_THROW_ON_ERROR));
                }

                return new ConnectorHttpResult(404, [], '{}');
            },
        ));

        $configuration = $service->configureAttributeSet($account, 9);

        $this->assertSame(
            9,
            AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId,
        );
    }

    #[Test]
    public function configure_attribute_set_rejects_missing_catalogue_id(): void
    {
        $account = $this->createConnectorAccount();
        $service = $this->serviceWithTransport(new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request): ConnectorHttpResult {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                    ],
                ], JSON_THROW_ON_ERROR));
            },
        ));

        $this->expectException(ConnectorExecutionConfigurationValidationException::class);

        $service->configureAttributeSet($account, 99);
    }

    #[Test]
    public function ensure_products_export_configuration_propagates_setup_required(): void
    {
        $account = $this->createConnectorAccount();
        $service = $this->serviceWithTransport(new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request): ConnectorHttpResult {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                        ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                    ],
                ], JSON_THROW_ON_ERROR));
            },
        ));

        $this->expectException(AdobeProductExportSetupRequiredException::class);

        $service->ensureProductsExportConfiguration($account);
    }

    #[Test]
    public function ensure_products_export_configuration_auto_selects_single_attribute_set(): void
    {
        $account = $this->createConnectorAccount();
        $service = $this->serviceWithTransport(new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request): ConnectorHttpResult {
                $uri = (string) $request->request->getUri();

                if (str_contains($uri, '/attribute-sets/sets/list')) {
                    return new ConnectorHttpResult(200, [], json_encode([
                        'items' => [
                            ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                        ],
                    ], JSON_THROW_ON_ERROR));
                }

                if (str_contains($uri, '/attribute-sets/9/attributes')) {
                    return new ConnectorHttpResult(200, [], json_encode([], JSON_THROW_ON_ERROR));
                }

                return new ConnectorHttpResult(404, [], '{}');
            },
        ));

        $configuration = $service->ensureProductsExportConfiguration($account);

        $this->assertSame(
            9,
            AdobeProductExportExecutionConfiguration::fromPayload(
                $configuration->connectorExecutionConfiguration()->payload(),
            )->attributeSetId,
        );
    }

    #[Test]
    public function ensure_products_export_configuration_rejects_empty_attribute_set_catalogue(): void
    {
        $account = $this->createConnectorAccount();
        $service = $this->serviceWithTransport(new RecordingConnectorHttpTransport(
            function (ConnectorOutboundRequest $request): ConnectorHttpResult {
                return new ConnectorHttpResult(200, [], json_encode(['items' => []], JSON_THROW_ON_ERROR));
            },
        ));

        $this->expectException(\RuntimeException::class);

        $service->ensureProductsExportConfiguration($account);
    }

    private function serviceWithTransport(RecordingConnectorHttpTransport $transport): AdobeProductExportSetupService
    {
        $reader = new AdobeProductExportMetadataReader(
            app(AdobePaaSRequestContextFactory::class),
            app(AdobeProductExportMetadataRequestFactory::class),
            $transport,
        );

        return new AdobeProductExportSetupService(
            app(SyncConfigurationReachabilityService::class),
            $reader,
            app(SyncConfigurationService::class),
        );
    }
}
