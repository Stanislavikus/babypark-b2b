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
    public function it_uses_attribute_sets_list_route_with_search_criteria(): void
    {
        $account = $this->createConnectorAccount();
        $capturedUris = [];

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request) use (&$capturedUris): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();
            $capturedUris[] = $uri;

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/attribute-sets/9/attributes')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    [
                        'attribute_id' => 100,
                        'attribute_code' => 'color',
                        'frontend_input' => 'select',
                        'scope' => 'global',
                        'options' => [
                            ['value' => '93', 'label' => 'Red'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        }));

        $metadata = $reader->read($account->workspace_id, $account->id);

        $this->assertSame(9, $metadata->selectedAttributeSetId);
        $this->assertGreaterThanOrEqual(2, count($capturedUris));
        $this->assertStringContainsString('/attribute-sets/sets/list', $capturedUris[0]);
        $this->assertStringContainsString('searchCriteria', $capturedUris[0]);
        $this->assertStringNotContainsString('searchCriteria', (string) end($capturedUris));
    }

    #[Test]
    public function it_auto_selects_when_only_one_attribute_set_exists(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/attribute-sets/9/attributes')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    [
                        'attribute_id' => 100,
                        'attribute_code' => 'color',
                        'frontend_input' => 'select',
                        'scope' => 'global',
                        'options' => [
                            ['value' => '93', 'label' => 'Red'],
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
    public function it_returns_stale_explicit_attribute_set_without_attributes(): void
    {
        $account = $this->createConnectorAccount();

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request): ConnectorHttpResult {
            return new ConnectorHttpResult(200, [], json_encode([
                'items' => [
                    ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                ],
            ], JSON_THROW_ON_ERROR));
        }));

        $metadata = $reader->read($account->workspace_id, $account->id, 99);

        $this->assertSame(99, $metadata->selectedAttributeSetId);
        $this->assertSame([], $metadata->attributes);
    }

    #[Test]
    public function it_fetches_options_from_top_level_array_without_search_criteria(): void
    {
        $account = $this->createConnectorAccount();
        $capturedUris = [];

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request) use (&$capturedUris): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();
            $capturedUris[] = $uri;

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 4, 'attribute_set_name' => 'Default'],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/attribute-sets/4/attributes')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    [
                        'attribute_id' => 200,
                        'attribute_code' => 'size',
                        'frontend_input' => 'select',
                        'scope' => 'global',
                        'options' => [],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/attributes/size/options')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    ['value' => '10', 'label' => 'Small'],
                    ['value' => '11', 'label' => 'Medium'],
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        }));

        $metadata = $reader->read($account->workspace_id, $account->id, null, ['size']);

        $optionsUri = collect($capturedUris)->first(
            static fn (string $uri): bool => str_contains($uri, '/attributes/size/options'),
        );

        $this->assertNotNull($optionsUri);
        $this->assertStringNotContainsString('searchCriteria', $optionsUri);
        $this->assertTrue($metadata->optionExists('size', '10'));
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
                'store_color' => new AdobeAttributeMetadata(
                    attributeId: 102,
                    code: 'store_color',
                    frontendInput: 'select',
                    scope: 'store',
                    options: ['93' => 'Red'],
                ),
            ],
        );

        $this->assertNotNull($metadata->attributeByCode('color'));
        $this->assertNull($metadata->attributeByCode('missing'));
        $this->assertTrue($metadata->isConfigurableCompatible('color'));
        $this->assertFalse($metadata->isConfigurableCompatible('name'));
        $this->assertFalse($metadata->isConfigurableCompatible('store_color'));
    }

    #[Test]
    public function it_enriches_mapped_attribute_scope_from_detail_endpoint_when_set_response_omits_scope(): void
    {
        $account = $this->createConnectorAccount();
        $capturedUris = [];

        $reader = $this->readerWithTransport(new RecordingConnectorHttpTransport(function (ConnectorOutboundRequest $request) use (&$capturedUris): ConnectorHttpResult {
            $uri = (string) $request->request->getUri();
            $capturedUris[] = $uri;

            if (str_contains($uri, '/attribute-sets/sets/list')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'items' => [
                        ['attribute_set_id' => 9, 'attribute_set_name' => 'Baby'],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/attribute-sets/9/attributes')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    [
                        'attribute_id' => 100,
                        'attribute_code' => 'color',
                        'frontend_input' => 'select',
                        'options' => [
                            ['value' => '93', 'label' => 'Red'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            if (str_contains($uri, '/products/attributes/color') && ! str_contains($uri, '/options')) {
                return new ConnectorHttpResult(200, [], json_encode([
                    'attribute_id' => 100,
                    'attribute_code' => 'color',
                    'frontend_input' => 'select',
                    'scope' => 'global',
                ], JSON_THROW_ON_ERROR));
            }

            return new ConnectorHttpResult(404, [], '{}');
        }));

        $metadata = $reader->read($account->workspace_id, $account->id, 9, ['color']);

        $this->assertTrue($metadata->isConfigurableCompatible('color'));
        $this->assertTrue(
            collect($capturedUris)->contains(
                static fn (string $uri): bool => str_contains($uri, '/products/attributes/color')
                    && ! str_contains($uri, '/options'),
            ),
        );
        $this->assertFalse(
            collect($capturedUris)->contains(
                static fn (string $uri): bool => str_contains($uri, '/products/attributes/name'),
            ),
        );
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
