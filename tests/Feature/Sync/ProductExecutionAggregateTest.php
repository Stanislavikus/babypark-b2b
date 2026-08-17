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

        $product->load('variants');

        $aggregate = app(ProductExecutionAggregateBuilder::class)->buildForProducts(collect([$product]))[0];

        $this->assertSame('Aggregate Product', $aggregate->productValues[$this->productBinding('name')->id]);
        $this->assertCount(1, $aggregate->variants);
        $this->assertSame('VAR-SKU', $aggregate->variants[0]->sku);
        $this->assertSame('blue', $aggregate->variants[0]->values[$this->productVariantBinding('color')->id]);
    }
}
