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

        $snapshot = [
            'version' => 'platform.sync-run-input.v1',
            'data_domain' => 'products',
            'semantic_operation' => 'export',
            'external_context' => [],
            'selection' => ['mode' => 'all_products'],
            'field_mappings' => [],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];

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
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingRequiredFieldMapping
                && $finding->subject === 'status',
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
        $this->assertSame('not_visible', $simpleChild->context['visibility']);
        $this->assertSame(1, $simpleChild->context['visibility_numeric']);
        $this->assertSame(1, $simpleChild->context['status']);
        $this->assertNotEmpty($simpleChild->context['resolved_configurable_values'] ?? []);
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

    #[Test]
    public function standalone_simple_product_uses_catalog_search_visibility(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SIMPLE-VIS',
            'name' => 'Simple Visibility Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SIMPLE-VAR',
            'is_active' => true,
        ]);
        $this->seedDefaultPrice($variant);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $this->assertSame(SyncPreviewOutcome::Ready, $result->outcome);
        $operation = collect($result->connectorPlan->operations)->first(
            fn ($op) => $op->operation === 'simple_product',
        );
        $this->assertNotNull($operation);
        $this->assertSame('catalog_search', $operation->context['visibility']);
        $this->assertSame(4, $operation->context['visibility_numeric']);
    }

    #[Test]
    public function inactive_product_maps_to_adobe_disabled_status(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'INACTIVE',
            'name' => 'Inactive Product',
            'is_active' => false,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'INACTIVE-VAR',
            'is_active' => true,
        ]);
        $this->seedDefaultPrice($variant);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $operation = collect($result->connectorPlan->operations)->first(
            fn ($op) => $op->operation === 'simple_product',
        );
        $this->assertNotNull($operation);
        $this->assertSame(2, $operation->context['status']);
    }

    #[Test]
    public function active_product_maps_to_adobe_enabled_status(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ACTIVE',
            'name' => 'Active Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ACTIVE-VAR',
            'is_active' => true,
        ]);
        $this->seedDefaultPrice($variant);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $operation = collect($result->connectorPlan->operations)->first(
            fn ($op) => $op->operation === 'simple_product',
        );
        $this->assertNotNull($operation);
        $this->assertSame(1, $operation->context['status']);
    }

    #[Test]
    public function ordinary_mapped_field_absent_from_selected_set_blocks_product(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ABSENT-FIELD',
            'name' => 'Absent Field Product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-ABSENT',
            'is_active' => true,
        ]);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
            ['field_binding_id' => $this->productBinding('description')->id, 'external_field_key' => 'missing_custom_attr'],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MappedFieldAbsentFromSelectedSet
                && $finding->subject === 'missing_custom_attr',
        ));
    }

    #[Test]
    public function required_missing_product_mapped_value_blocks_product(): void
    {
        $workspace = $this->defaultWorkspace();
        $descriptionBinding = $this->productBinding('description');
        $descriptionBinding->update(['is_required' => true]);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'REQ-PROD',
            'name' => 'Required Product Field',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'REQ-VAR',
            'is_active' => true,
        ]);
        $this->seedDefaultPrice($variant);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
            ['field_binding_id' => $descriptionBinding->id, 'external_field_key' => 'description'],
        ]);

        $metadata = $this->metadataFixture();
        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: array_merge($metadata->attributes, [
                'description' => new AdobeAttributeMetadata(
                    attributeId: 76,
                    code: 'description',
                    frontendInput: 'textarea',
                    scope: 'global',
                    options: [],
                ),
            ]),
        );

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $metadata);

        $this->assertSame(SyncPreviewOutcome::Blocked, $result->outcome);
        $this->assertTrue(collect($result->findings)->contains(
            fn ($finding) => $finding->code === SyncPreviewFindingCode::MissingMappedProductValue
                && $finding->subject === (string) $descriptionBinding->id,
        ));
    }

    #[Test]
    public function constant_variant_select_projects_through_option_mapping_on_simple_product(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CONST-SELECT',
            'name' => 'Constant Select Product',
            'is_active' => true,
        ]);

        $backorderBinding = $this->productVariantBinding('backorder_policy');
        $skuBinding = $this->productVariantBinding('sku');

        $variant = $this->createVariantWithBackorder($product, 'VAR-1', 'red', 'deny');
        $this->seedDefaultPrice($variant);

        $snapshot = $this->snapshotWithMappings([
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $skuBinding->id, 'external_field_key' => 'sku'],
            [
                'field_binding_id' => $backorderBinding->id,
                'external_field_key' => 'backorders',
                'option_mappings' => [
                    ['internal_option_key' => 'deny', 'external_option_value' => '0'],
                ],
            ],
        ]);

        $metadata = $this->metadataFixture();
        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: array_merge($metadata->attributes, [
                'backorders' => new AdobeAttributeMetadata(
                    attributeId: 103,
                    code: 'backorders',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['0' => 'No Backorders', '1' => 'Allow'],
                ),
            ]),
        );

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $metadata);

        $this->assertSame(SyncPreviewOutcome::Ready, $result->outcome);
        $operation = collect($result->connectorPlan->operations)->first(
            fn ($op) => $op->operation === 'simple_product',
        );
        $this->assertNotNull($operation);
        $mappedVariant = $operation->context['mapped_variant_values'] ?? [];
        $backorderEntry = $mappedVariant[$backorderBinding->id] ?? null;
        $this->assertNotNull($backorderEntry);
        $this->assertSame('deny', $backorderEntry['internal_value']);
        $this->assertSame('0', $backorderEntry['external_value']);
    }

    #[Test]
    public function configurable_parent_uses_catalog_search_visibility(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'CFG-PARENT-VIS',
            'name' => 'Configurable Parent Visibility',
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
                ],
            ],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $result = $this->planner->plan($aggregate, $snapshot, $this->metadataFixture());

        $parent = collect($result->connectorPlan->operations)->first(
            fn ($op) => $op->operation === 'configurable_parent',
        );
        $this->assertNotNull($parent);
        $this->assertSame('catalog_search', $parent->context['visibility']);
        $this->assertSame(4, $parent->context['visibility_numeric']);
    }

    private function metadataFixture(): AdobeProductExportExecutionMetadata
    {
        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: [
                'name' => new AdobeAttributeMetadata(
                    attributeId: 71,
                    code: 'name',
                    frontendInput: 'text',
                    scope: 'global',
                    options: [],
                ),
                'sku' => new AdobeAttributeMetadata(
                    attributeId: 74,
                    code: 'sku',
                    frontendInput: 'text',
                    scope: 'global',
                    options: [],
                ),
                'status' => new AdobeAttributeMetadata(
                    attributeId: 97,
                    code: 'status',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['1' => 'Enabled', '2' => 'Disabled'],
                ),
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
        $merged = $fieldMappings;

        foreach ($this->requiredCoreMappings() as $requiredMapping) {
            $exists = false;

            foreach ($fieldMappings as $mapping) {
                if (($mapping['external_field_key'] ?? null) === $requiredMapping['external_field_key']) {
                    $exists = true;
                    break;
                }
            }

            if (! $exists) {
                $merged[] = $requiredMapping;
            }
        }

        return [
            'version' => 'platform.sync-run-input.v1',
            'data_domain' => 'products',
            'semantic_operation' => 'export',
            'external_context' => [],
            'selection' => ['mode' => 'all_products'],
            'field_mappings' => $merged,
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requiredCoreMappings(): array
    {
        return [
            ['field_binding_id' => $this->productBinding('name')->id, 'external_field_key' => 'name'],
            ['field_binding_id' => $this->productVariantBinding('sku')->id, 'external_field_key' => 'sku'],
            ['field_binding_id' => $this->productBinding('status')->id, 'external_field_key' => 'status'],
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

    private function createVariantWithBackorder(
        Product $product,
        string $sku,
        string $color,
        string $backorderPolicy,
    ): ProductVariant {
        $variant = $this->createVariant($product, $sku, $color);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'variant_id' => $variant->id,
            'field_binding_id' => $this->productVariantBinding('backorder_policy')->id,
            'value_text' => $backorderPolicy,
        ]);

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
