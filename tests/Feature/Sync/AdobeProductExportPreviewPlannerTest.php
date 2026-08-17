<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Support\Connectors\AdobePaaS\AdobeProductExportPreviewPlanner;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class AdobeProductExportPreviewPlannerTest extends TestCase
{
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private AdobeProductExportPreviewPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seedFieldDefinitions();
        $this->planner = new AdobeProductExportPreviewPlanner;
    }

    #[Test]
    public function simple_product_without_name_is_blocked(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-1',
            'name' => '',
            'is_active' => true,
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProducts(collect([$product]))[0];
        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
        ]);

        $result = $this->planner->plan($aggregate, $snapshot);

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingName,
        ));
    }

    #[Test]
    public function configurable_product_with_null_variant_color_blocks_product(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-PARENT',
            'name' => 'Configurable Product',
            'is_active' => true,
        ]);

        $colorBinding = $this->productVariantBinding('color');
        $skuBinding = $this->productVariantBinding('sku');

        $variantA = $this->createVariant($product, 'VAR-A', 'blue');
        $variantB = $this->createVariant($product, 'VAR-B', 'red');
        $variantC = $this->createVariant($product, 'VAR-C', null);

        $this->assertNotNull($variantA);
        $this->assertNotNull($variantB);

        $product->load('variants');

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProducts(collect([$product]))[0];

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $skuBinding->id, 'external_field_key' => 'sku'],
            [
                'field_binding_id' => $colorBinding->id,
                'external_field_key' => 'color',
                'option_mappings' => [
                    ['internal_option_key' => 'blue', 'external_option_value' => '93'],
                    ['internal_option_key' => 'red', 'external_option_value' => '94'],
                ],
            ],
        ]);

        $result = $this->planner->plan($aggregate, $snapshot);

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingVariantOptionValue
                && $finding->subject === (string) $variantC->id,
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $fieldMappings
     * @return array<string, mixed>
     */
    private function snapshotWithMappings(array $fieldMappings): array
    {
        return [
            'version' => 'babypark.sync-run-input.v1',
            'data_domain' => 'products',
            'semantic_operation' => 'export',
            'external_context' => [],
            'selection' => ['mode' => 'all_products'],
            'field_mappings' => $fieldMappings,
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
    }

    private function createVariant(Product $product, string $sku, ?string $color): ProductVariant
    {
        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => $sku,
            'is_active' => true,
        ]);

        if ($color !== null) {
            VariantFieldValue::withoutWorkspaceScope()->create([
                'workspace_id' => $product->workspace_id,
                'variant_id' => $variant->id,
                'field_binding_id' => $this->productVariantBinding('color')->id,
                'value_text' => $color,
            ]);
        }

        return $variant;
    }
}
