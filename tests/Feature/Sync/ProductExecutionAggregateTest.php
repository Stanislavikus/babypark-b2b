<?php

namespace Tests\Feature\Sync;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Support\Sync\Preview\ProductExecutionAggregateBuilder;
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
}
