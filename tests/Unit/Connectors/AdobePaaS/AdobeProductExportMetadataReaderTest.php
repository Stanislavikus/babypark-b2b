<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobePaaSRequestContextFactory;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataReader;
use App\Support\Connectors\AdobePaaS\AdobeProductExportMetadataRequestFactory;
use App\Support\Connectors\AdobePaaS\Exceptions\AdobeProductExportSetupRequiredException;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductExportMetadataReaderTest extends TestCase
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
    public function it_auto_selects_when_only_one_attribute_set_exists(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/attribute-sets/sets')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/attribute-sets/9/attributes')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        [
                            'attribute_id' => 100,
                            'attribute_code' => 'color',
                            'frontend_input' => 'select',
                            'scope' => 'global',
                            'options' => [
                                ['value' => '93', 'label' => 'Red'],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        }));

        $metadata = $reader->read($account->workspace_id, $account->id);

        $this->assertSame(9, $metadata->selectedAttributeSetId);
        $this->assertTrue($metadata->isConfigurableCompatible('color'));
        $this->assertTrue($metadata->optionExists('color', '93'));
        $this->assertFalse($metadata->optionExists('color', '94'));
    }

    #[Test]
    public function it_requires_setup_when_multiple_sets_exist_without_explicit_id(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            return new ConnectorHttpResult(200, [], json_encode([
                'items' => [
                    ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                    ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                ],
            ], JSON_THROW_ON_ERROR));
        }));

        $this->expectException(AdobeProductExportSetupRequiredException::class);

        $reader->read($account->workspace_id, $account->id);
    }

    #[Test]
    public function it_throws_when_explicit_attribute_set_id_does_not_exist(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            return new ConnectorHttpResult(200, [], json_encode([
                'items' => [
                    ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                ],
            ], JSON_THROW_ON_ERROR));
        }));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $reader->read($account->workspace_id, $account->id, 99);
    }

    #[Test]
    public function execution_metadata_exposes_attribute_lookup_helpers(): void
    {
        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'color' => new AdobeAttributeMetadata(
                    attributeId: 100,
                    code: 'color',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['93' => 'Red'],
                ),
                'name' => new AdobeAttributeMetadata(
                    attributeId: 101,
                    code: 'name',
                    frontendInput: 'text',
                    scope: 'global',
                    options: [],
                ),
            ],
        );

        $this->assertNotNull($metadata->attributeByCode('color'));
        $this->assertNull($metadata->attributeByCode('missing'));
        $this->assertTrue($metadata->isConfigurableCompatible('color'));
        $this->assertFalse($metadata->isConfigurableCompatible('name'));
    }

    private function readerWithTransport(RecordingConnectorHttpTransport $transport): AdobeProductExportMetadataReader
    {
        return new AdobeProductExportMetadataReader(
            app(AdobePaaSRequestContextFactory::class),
            app(AdobeProductExportMetadataRequestFactory::class),
            $transport,
        );
    }
}
