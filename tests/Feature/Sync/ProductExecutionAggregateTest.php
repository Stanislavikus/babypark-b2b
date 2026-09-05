<?php

namespace Tests\Feature\Sync;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
use App\Support\Sync\Preview\ProductExecutionImageStructuralState;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductExecutionAggregateTest extends TestCase
{
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seedFieldDefinitions();
    }

    #[Test]
    public function builder_resolves_column_and_dynamic_variant_values(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PARENT',
            'name' => 'Aggregate Product',
            'is_active' => true,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-SKU',
            'is_active' => true,
        ]);

        VariantFieldValue::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'field_binding_id' => $this->productVariantBinding('color')->id,
            'value_text' => 'blue',
        ]);

        $snapshot = [
            'field_mappings' => [
                [
                    'field_binding_id' => $this->productBinding('name')->id,
                    'external_field_key' => 'name',
                ],
                [
                    'field_binding_id' => $this->productVariantBinding('sku')->id,
                    'external_field_key' => 'sku',
                ],
                [
                    'field_binding_id' => $this->productVariantBinding('color')->id,
                    'external_field_key' => 'color',
                ],
            ],
        ];

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $nameBindingId = $this->productBinding('name')->id;
        $skuBindingId = $this->productVariantBinding('sku')->id;
        $colorBindingId = $this->productVariantBinding('color')->id;

        $this->assertSame('Aggregate Product', $aggregate->productValues[$nameBindingId]->value);
        $this->assertSame(1, $aggregate->sellableVariantCount);
        $this->assertTrue($aggregate->hasSellableVariants());
        $this->assertFalse($aggregate->hasMultipleSellableVariants());
        $this->assertSame('VAR-SKU', $aggregate->variants[0]->values[$skuBindingId]->value);
        $this->assertSame('blue', $aggregate->variants[0]->values[$colorBindingId]->value);
    }

    #[Test]
    public function builder_returns_aggregate_when_zero_field_mappings(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PARENT',
            'name' => 'No Mapping Product',
            'is_active' => true,
        ]);

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'VAR-SKU',
            'is_active' => true,
        ]);

        $aggregates = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            ['field_mappings' => []],
        );

        $this->assertCount(1, $aggregates);
        $this->assertSame((string) $product->id, $aggregates[0]->productId);
        $this->assertSame([], $aggregates[0]->productValues);
    }

    #[Test]
    public function builder_retains_mapped_binding_descriptor_when_value_is_null(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'PARENT',
            'name' => 'Null Value Product',
            'is_active' => true,
        ]);

        $descriptionBinding = $this->productBinding('description');

        $snapshot = [
            'field_mappings' => [
                [
                    'field_binding_id' => $descriptionBinding->id,
                    'external_field_key' => 'description',
                ],
            ],
        ];

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            $snapshot,
        )[0];

        $mapped = $aggregate->productValues[$descriptionBinding->id] ?? null;

        $this->assertNotNull($mapped);
        $this->assertSame('description', $mapped->internalCode);
        $this->assertNull($mapped->value);
    }

    #[Test]
    public function builder_returns_empty_image_input_when_images_are_null(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'NO-IMAGES',
            'name' => 'No Images Product',
            'is_active' => true,
            'images' => null,
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            ['field_mappings' => []],
        )[0];

        $this->assertSame(ProductExecutionImageStructuralState::Valid, $aggregate->imageInput->structuralState);
        $this->assertSame([], $aggregate->imageInput->entries);
        $this->assertFalse($aggregate->imageInput->hasEntries());
    }

    #[Test]
    public function builder_marks_image_input_malformed_when_images_is_not_array(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'BAD-IMAGES',
            'name' => 'Bad Images Product',
            'is_active' => true,
            'images' => 'not-an-array',
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            ['field_mappings' => []],
        )[0];

        $this->assertSame(ProductExecutionImageStructuralState::Malformed, $aggregate->imageInput->structuralState);
        $this->assertSame([], $aggregate->imageInput->entries);
    }

    #[Test]
    public function builder_marks_associative_images_json_as_malformed(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'ASSOC-IMAGES',
            'name' => 'Associative Images Product',
            'is_active' => true,
            'images' => ['primary' => 'https://cdn.example.test/primary.jpg'],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            ['field_mappings' => []],
        )[0];

        $this->assertSame(ProductExecutionImageStructuralState::Malformed, $aggregate->imageInput->structuralState);
        $this->assertSame([], $aggregate->imageInput->entries);
    }

    #[Test]
    public function builder_maps_product_images_preserving_declaration_order(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'WITH-IMAGES',
            'name' => 'Images Product',
            'is_active' => true,
            'images' => [
                'https://cdn.example.test/primary.jpg',
                'https://cdn.example.test/gallery.jpg',
            ],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            ['field_mappings' => []],
        )[0];

        $this->assertCount(2, $aggregate->imageInput->entries);
        $this->assertSame(0, $aggregate->imageInput->entries[0]->declarationIndex);
        $this->assertSame('https://cdn.example.test/primary.jpg', $aggregate->imageInput->entries[0]->sourceReference);
        $this->assertSame(1, $aggregate->imageInput->entries[1]->declarationIndex);
        $this->assertSame('https://cdn.example.test/gallery.jpg', $aggregate->imageInput->entries[1]->sourceReference);
    }

    #[Test]
    public function builder_marks_non_string_image_values_as_malformed_entries(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'MALFORMED-IMAGE-ENTRY',
            'name' => 'Malformed Image Entry Product',
            'is_active' => true,
            'images' => ['https://cdn.example.test/valid.jpg', '', 123],
        ]);

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProductIds(
            (string) $workspace->id,
            [(string) $product->id],
            ['field_mappings' => []],
        )[0];

        $this->assertSame(ProductExecutionImageStructuralState::Valid, $aggregate->imageInput->structuralState);
        $this->assertFalse($aggregate->imageInput->entries[0]->isMalformed);
        $this->assertTrue($aggregate->imageInput->entries[1]->isMalformed);
        $this->assertTrue($aggregate->imageInput->entries[2]->isMalformed);
        $this->assertNull($aggregate->imageInput->entries[1]->sourceReference);
    }
}
