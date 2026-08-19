<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\AttributeDataType;
use App\Enums\FieldObjectType;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use App\Services\Pricing\ResolvedPrice;
use App\Support\Connectors\AdobePaaS\AdobeAttributeMetadata;
use App\Support\Connectors\AdobePaaS\AdobeProductExportExecutionMetadata;
use App\Support\Connectors\AdobePaaS\Semantic\AdobeProductExportSemanticPlanner;
use App\Support\Sync\Preview\MappedFieldValue;
use App\Support\Sync\Preview\ProductExecutionAggregate;
use App\Support\Sync\Preview\ProductVariantExecutionSlice;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductExportSemanticPlannerTest extends TestCase
{
    private AdobeProductExportSemanticPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new AdobeProductExportSemanticPlanner;
    }

    #[Test]
    public function standalone_simple_product_produces_simple_product_operation(): void
    {
        $aggregate = $this->simpleAggregate();
        $snapshot = $this->snapshotWithCoreMappings();

        $result = $this->planner->evaluate($aggregate, $snapshot, $this->metadataFixture());

        $this->assertFalse($result->hasBlockingFindings());
        $this->assertCount(1, $result->operations);
        $this->assertSame('simple_product', $result->operations[0]->operation);
        $this->assertSame('catalog_search', $result->operations[0]->context['visibility']);
        $this->assertSame(4, $result->operations[0]->context['visibility_numeric']);
        $this->assertSame(1, $result->operations[0]->context['status']);
        $this->assertNotNull($result->operations[0]->context['resolved_price']);
        $this->assertSame(100.0, $result->operations[0]->context['resolved_price']['effective_net_price']);
    }

    #[Test]
    public function configurable_product_produces_parent_attributes_children_and_links(): void
    {
        $aggregate = $this->configurableAggregate();
        $snapshot = $this->configurableSnapshot();
        $metadata = $this->metadataFixture();

        $result = $this->planner->evaluate($aggregate, $snapshot, $metadata);

        $this->assertFalse($result->hasBlockingFindings());
        $operationTypes = array_map(
            static fn ($operation) => $operation->operation,
            $result->operations,
        );

        $this->assertContains('configurable_parent', $operationTypes);
        $this->assertContains('configurable_attribute', $operationTypes);
        $this->assertContains('option_assignment', $operationTypes);
        $this->assertContains('simple_child', $operationTypes);
        $this->assertContains('child_link', $operationTypes);

        $child = collect($result->operations)->first(
            fn ($operation) => $operation->operation === 'simple_child',
        );
        $this->assertNotNull($child);
        $this->assertSame('not_visible', $child->context['visibility']);
        $this->assertSame(1, $child->context['visibility_numeric']);
        $this->assertNotEmpty($child->context['resolved_configurable_values']);
    }

    #[Test]
    public function missing_required_product_mapped_value_emits_blocking_finding(): void
    {
        $aggregate = $this->simpleAggregate(requiredDescriptionMissing: true);
        $snapshot = $this->snapshotWithCoreMappings(includeDescription: true);
        $metadata = $this->metadataFixture(withDescription: true);

        $result = $this->planner->evaluate($aggregate, $snapshot, $metadata);

        $this->assertTrue($result->hasBlockingFindings());
        $this->assertTrue($result->hasFindingCode('missing_mapped_product_value'));
    }

    #[Test]
    public function select_option_mapping_projects_external_value(): void
    {
        $bindingId = 'binding-backorders';
        $aggregate = new ProductExecutionAggregate(
            productId: 'product-1',
            productValues: [
                'binding-name' => $this->mappedValue('binding-name', 'name', FieldObjectType::Product, AttributeDataType::Text, 'Product'),
                'binding-status' => $this->mappedValue('binding-status', 'status', FieldObjectType::Product, AttributeDataType::Boolean, true),
            ],
            variants: [
                new ProductVariantExecutionSlice(
                    variantId: 'variant-1',
                    values: [
                        'binding-sku' => $this->mappedValue('binding-sku', 'sku', FieldObjectType::ProductVariant, AttributeDataType::Text, 'SKU-1'),
                        $bindingId => $this->mappedValue($bindingId, 'backorder_policy', FieldObjectType::ProductVariant, AttributeDataType::Select, 'deny'),
                    ],
                    resolvedPrice: $this->makeResolvedPrice(),
                    priceResolutionStatus: PriceResolutionStatus::Resolved->value,
                ),
            ],
            sellableVariantCount: 1,
        );

        $snapshot = [
            'field_mappings' => [
                ['field_binding_id' => 'binding-name', 'external_field_key' => 'name'],
                ['field_binding_id' => 'binding-sku', 'external_field_key' => 'sku'],
                ['field_binding_id' => 'binding-status', 'external_field_key' => 'status'],
                [
                    'field_binding_id' => $bindingId,
                    'external_field_key' => 'backorders',
                    'option_mappings' => [
                        ['internal_option_key' => 'deny', 'external_option_value' => '0'],
                    ],
                ],
            ],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];

        $metadata = new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: array_merge($this->metadataFixture()->attributes, [
                'backorders' => new AdobeAttributeMetadata(
                    attributeId: 103,
                    code: 'backorders',
                    frontendInput: 'select',
                    scope: 'global',
                    options: ['0' => 'No Backorders'],
                ),
            ]),
        );

        $result = $this->planner->evaluate($aggregate, $snapshot, $metadata);

        $this->assertFalse($result->hasBlockingFindings());
        $mappedVariant = $result->operations[0]->context['mapped_variant_values'][$bindingId] ?? null;
        $this->assertNotNull($mappedVariant);
        $this->assertSame('deny', $mappedVariant['internal_value']);
        $this->assertSame('0', $mappedVariant['external_value']);
    }

    #[Test]
    public function inactive_product_maps_to_adobe_disabled_status(): void
    {
        $aggregate = $this->simpleAggregate(active: false);
        $snapshot = $this->snapshotWithCoreMappings();

        $result = $this->planner->evaluate($aggregate, $snapshot, $this->metadataFixture());

        $this->assertSame(2, $result->operations[0]->context['status']);
    }

    #[Test]
    public function no_configurable_dimension_emits_blocking_finding_for_multi_variant_constant_select(): void
    {
        $aggregate = $this->configurableAggregate(constantColor: true);
        $snapshot = $this->configurableSnapshot();

        $result = $this->planner->evaluate($aggregate, $snapshot, $this->metadataFixture());

        $this->assertTrue($result->hasBlockingFindings());
        $this->assertTrue($result->hasFindingCode('no_configurable_dimension'));
    }

    private function makeResolvedPrice(): ResolvedPrice
    {
        return new ResolvedPrice(
            regularNetPrice: 100.0,
            salePrice: null,
            effectiveNetPrice: 100.0,
            vatRate: 20.0,
            grossPrice: 120.0,
            currency: 'UAH',
            source: 'default_price_list',
            sourcePriceListId: null,
            sourcePriceListItemId: null,
            regularGrossPrice: 120.0,
            isOnSale: false,
        );
    }

    private function mappedValue(
        string $fieldBindingId,
        string $internalCode,
        FieldObjectType $objectType,
        AttributeDataType $dataType,
        mixed $value,
        bool $isRequired = false,
    ): MappedFieldValue {
        return new MappedFieldValue(
            fieldBindingId: $fieldBindingId,
            internalCode: $internalCode,
            objectType: $objectType,
            dataType: $dataType,
            isRequired: $isRequired,
            isMultiValue: false,
            value: $value,
        );
    }

    private function simpleAggregate(bool $active = true, bool $requiredDescriptionMissing = false): ProductExecutionAggregate
    {
        $productValues = [
            'binding-name' => $this->mappedValue('binding-name', 'name', FieldObjectType::Product, AttributeDataType::Text, 'Simple Product'),
            'binding-status' => $this->mappedValue('binding-status', 'status', FieldObjectType::Product, AttributeDataType::Boolean, $active),
        ];

        if ($requiredDescriptionMissing) {
            $productValues['binding-description'] = $this->mappedValue(
                'binding-description',
                'description',
                FieldObjectType::Product,
                AttributeDataType::Text,
                null,
                isRequired: true,
            );
        }

        return new ProductExecutionAggregate(
            productId: 'product-1',
            productValues: $productValues,
            variants: [
                new ProductVariantExecutionSlice(
                    variantId: 'variant-1',
                    values: [
                        'binding-sku' => $this->mappedValue('binding-sku', 'sku', FieldObjectType::ProductVariant, AttributeDataType::Text, 'SKU-1'),
                    ],
                    resolvedPrice: $this->makeResolvedPrice(),
                    priceResolutionStatus: PriceResolutionStatus::Resolved->value,
                ),
            ],
            sellableVariantCount: 1,
        );
    }

    private function configurableAggregate(bool $constantColor = false): ProductExecutionAggregate
    {
        $colorValues = $constantColor
            ? ['red', 'red']
            : ['blue', 'red'];

        return new ProductExecutionAggregate(
            productId: 'product-cfg',
            productValues: [
                'binding-name' => $this->mappedValue('binding-name', 'name', FieldObjectType::Product, AttributeDataType::Text, 'Configurable Product'),
                'binding-status' => $this->mappedValue('binding-status', 'status', FieldObjectType::Product, AttributeDataType::Boolean, true),
            ],
            variants: [
                $this->variantSlice('variant-1', 'SKU-1', $colorValues[0]),
                $this->variantSlice('variant-2', 'SKU-2', $colorValues[1]),
            ],
            sellableVariantCount: 2,
        );
    }

    private function variantSlice(string $variantId, string $sku, string $color): ProductVariantExecutionSlice
    {
        return new ProductVariantExecutionSlice(
            variantId: $variantId,
            values: [
                'binding-sku' => $this->mappedValue('binding-sku', 'sku', FieldObjectType::ProductVariant, AttributeDataType::Text, $sku),
                'binding-color' => $this->mappedValue('binding-color', 'color', FieldObjectType::ProductVariant, AttributeDataType::Select, $color),
            ],
            resolvedPrice: $this->makeResolvedPrice(),
            priceResolutionStatus: PriceResolutionStatus::Resolved->value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotWithCoreMappings(bool $includeDescription = false): array
    {
        $fieldMappings = [
            ['field_binding_id' => 'binding-name', 'external_field_key' => 'name'],
            ['field_binding_id' => 'binding-sku', 'external_field_key' => 'sku'],
            ['field_binding_id' => 'binding-status', 'external_field_key' => 'status'],
        ];

        if ($includeDescription) {
            $fieldMappings[] = ['field_binding_id' => 'binding-description', 'external_field_key' => 'description'];
        }

        return [
            'field_mappings' => $fieldMappings,
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurableSnapshot(): array
    {
        return [
            'field_mappings' => [
                ['field_binding_id' => 'binding-name', 'external_field_key' => 'name'],
                ['field_binding_id' => 'binding-sku', 'external_field_key' => 'sku'],
                ['field_binding_id' => 'binding-status', 'external_field_key' => 'status'],
                [
                    'field_binding_id' => 'binding-color',
                    'external_field_key' => 'color',
                    'option_mappings' => [
                        ['internal_option_key' => 'blue', 'external_option_value' => '93'],
                        ['internal_option_key' => 'red', 'external_option_value' => '94'],
                    ],
                ],
            ],
            'connector_execution_configuration' => ['attribute_set_id' => 4],
        ];
    }

    private function metadataFixture(bool $withDescription = false): AdobeProductExportExecutionMetadata
    {
        $attributes = [
            'name' => new AdobeAttributeMetadata(71, 'name', 'text', 'global', []),
            'sku' => new AdobeAttributeMetadata(74, 'sku', 'text', 'global', []),
            'status' => new AdobeAttributeMetadata(97, 'status', 'select', 'global', ['1' => 'Enabled', '2' => 'Disabled']),
            'color' => new AdobeAttributeMetadata(100, 'color', 'select', 'global', ['93' => 'Blue', '94' => 'Red']),
        ];

        if ($withDescription) {
            $attributes['description'] = new AdobeAttributeMetadata(76, 'description', 'textarea', 'global', []);
        }

        return new AdobeProductExportExecutionMetadata(
            selectedAttributeSetId: 4,
            attributeSets: [['attribute_set_id' => 4, 'attribute_set_name' => 'Default']],
            attributes: $attributes,
        );
    }
}
