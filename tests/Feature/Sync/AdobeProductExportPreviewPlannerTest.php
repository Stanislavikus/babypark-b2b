<?php

namespace Tests\Feature\Sync;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Enums\SyncPreviewFindingCode;
use App\Enums\SyncPreviewOutcome;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
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

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

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

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot);

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingMappedVariantValue
                && $finding->subject === (string) $variantC->id,
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::ConfigurableVariantsIncomplete,
        ));
    }

    #[Test]
    public function zero_mappings_blocks_product_without_failing_run_semantics(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-ZERO',
            'name' => 'Zero Mapping Product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-ZERO',
            'is_active' => true,
        ]);

        $snapshot = $this->snapshotWithMappings([]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot);

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingRequiredFieldMapping
                && $finding->subject === 'name',
        ));
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingRequiredFieldMapping
                && $finding->subject === 'sku',
        ));
    }

    #[Test]
    public function constant_variant_select_does_not_qualify_as_configurable_dimension(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-CONST',
            'name' => 'Constant Color Product',
            'is_active' => true,
        ]);

        $colorBinding = $this->productVariantBinding('color');
        $skuBinding = $this->productVariantBinding('sku');

        $this->createVariant($product, 'VAR-1', 'red');
        $this->createVariant($product, 'VAR-2', 'red');

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $skuBinding->id, 'external_field_key' => 'sku'],
            [
                'field_binding_id' => $colorBinding->id,
                'external_field_key' => 'color',
                'option_mappings' => [
                    ['internal_option_key' => 'red', 'external_option_value' => '94'],
                ],
            ],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::NoConfigurableDimension,
        ));
    }

    #[Test]
    public function configurable_plan_carries_attribute_id_and_used_value_index_only(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-PLAN',
            'name' => 'Plan Product',
            'is_active' => true,
        ]);

        $colorBinding = $this->productVariantBinding('color');
        $skuBinding = $this->productVariantBinding('sku');

        $variantBlue = $this->createVariant($product, 'VAR-BLUE', 'blue');
        $variantRed = $this->createVariant($product, 'VAR-RED', 'red');
        $this->seedDefaultPrice($variantBlue);
        $this->seedDefaultPrice($variantRed);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $skuBinding->id, 'external_field_key' => 'sku'],
            [
                'field_binding_id' => $colorBinding->id,
                'external_field_key' => 'color',
                'option_mappings' => [
                    ['internal_option_key' => 'blue', 'external_option_value' => '93'],
                    ['internal_option_key' => 'red', 'external_option_value' => '94'],
                    ['internal_option_key' => 'green', 'external_option_value' => '95'],
                ],
            ],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $this->assertSame(SyncPreviewOutcome::Ready, $result->outcome);
        $this->assertNotNull($result->connectorPlan);

        $operations = $result->connectorPlan->operations;
        $configurableAttribute = collect($operations)->first(
            fn ($operation) => $operation->operation === 'configurable_attribute',
        );
        $this->assertNotNull($configurableAttribute);
        $this->assertSame(100, $configurableAttribute->context['attribute_id']);

        $optionAssignments = collect($operations)
            ->filter(fn ($operation) => $operation->operation === 'option_assignment')
            ->values();

        $this->assertCount(2, $optionAssignments);
        $this->assertTrue($optionAssignments->contains(
            fn ($operation) => ($operation->context['value_index'] ?? null) === '93',
        ));
        $this->assertFalse($optionAssignments->contains(
            fn ($operation) => ($operation->context['value_index'] ?? null) === '95',
        ));

        $simpleChild = collect($operations)->first(
            fn ($operation) => $operation->operation === 'simple_child',
        );
        $this->assertNotNull($simpleChild);
        $this->assertSame('VAR-BLUE', $simpleChild->context['sku']);
        $this->assertNotNull($simpleChild->context['resolved_price']);
    }

    #[Test]
    public function non_global_adobe_attribute_blocks_configurable_axis(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-STORE',
            'name' => 'Store Scoped Product',
            'is_active' => true,
        ]);

        $materialBinding = $this->productVariantBinding('color');
        $skuBinding = $this->productVariantBinding('sku');

        $this->createVariant($product, 'VAR-1', 'blue');
        $this->createVariant($product, 'VAR-2', 'red');

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $skuBinding->id, 'external_field_key' => 'sku'],
            [
                'field_binding_id' => $materialBinding->id,
                'external_field_key' => 'store_color',
                'option_mappings' => [
                    ['internal_option_key' => 'blue', 'external_option_value' => '93'],
                    ['internal_option_key' => 'red', 'external_option_value' => '94'],
                ],
            ],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::InvalidConfigurableAttribute
                && $finding->subject === 'store_color',
        ));
    }

    #[Test]
    public function stale_attribute_set_in_snapshot_blocks_product(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'STALE-SET',
            'name' => 'Stale Set Product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-STALE',
            'is_active' => true,
        ]);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
        ]);
        $snapshot['connector_execution_configuration'] = ['attribute_set_id' => 99];

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 99,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [],
        );

        $result = $this->planner->plan($aggregate, $snapshot, $metadata);

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::AttributeSetInvalid,
        ));
    }

    private function metadataFixture(): AdobeProductExportExecutionMetadata
    {
        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'color' => new AdobeAttributeMetadata(
                    attributeId: 100,
                    code: 'color',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['93' => 'Blue', '94' => 'Red', '95' => 'Green'],
                ),
                'size' => new AdobeAttributeMetadata(
                    attributeId: 101,
                    code: 'size',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['10' => 'S', '11' => 'M'],
                ),
                'store_color' => new AdobeAttributeMetadata(
                    attributeId: 102,
                    code: 'store_color',
                    frontendInput: 'select',
                    scope: 'store',
                    options: ['93' => 'Blue', '94' => 'Red'],
                ),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fieldMappings
     * @return array<string, mixed>
     */
    private function snapshotWithMappings(array $fieldMappings): array
    {
        return [
            'version' => 'platform.sync-run-input.v1',
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

    private function seedDefaultPrice(ProductVariant $variant): void
    {
        $priceList = PriceList::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $variant->workspace_id,
                'is_default' => true,
            ],
            [
                'name' => 'Workspace Default',
                'currency' => 'UAH',
                'priority' => 0,
                'status' => PriceListStatus::Active,
            ],
        );

        PriceListItem::withoutWorkspaceScope()->create([
            'price_list_id' => $priceList->id,
            'product_variant_id' => $variant->id,
            'quantity_min' => 1,
            'price' => 100.0,
            'sale_price' => null,
            'vat_rate' => 20,
            'status' => PriceListItemStatus::Active,
        ]);
    }
}
