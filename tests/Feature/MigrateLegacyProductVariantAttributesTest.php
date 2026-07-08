<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantAttributeValue;
use App\Models\Workspace;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateLegacyProductVariantAttributesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Workspace::query()->create([
            'name' => 'Babypark',
            'is_default' => true,
        ]);

        $this->seed(AttributeDefinitionSeeder::class);
    }

    public function test_migrates_legacy_attributes_idempotently(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'FIXTURE-001',
            'name' => 'Fixture product',
            'is_active' => true,
        ]);

        $blueVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'FIXTURE-001-BLUE',
            'attributes' => ['Колір' => 'Синій'],
            'is_active' => true,
        ]);

        $pinkVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'FIXTURE-001-PINK',
            'attributes' => ['Колір' => 'Рожевий'],
            'is_active' => true,
        ]);

        $sizeVariant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => fake()->uuid(),
            'sku' => 'FIXTURE-001-SIZE',
            'attributes' => ['Розмір' => 'M'],
            'is_active' => true,
        ]);

        $this->artisan('product-fields:migrate-legacy-attributes')
            ->assertSuccessful();

        $this->assertSame(3, VariantAttributeValue::withoutWorkspaceScope()->count());

        $colorDefinition = AttributeDefinition::withoutWorkspaceScope()->where('code', 'color')->firstOrFail();
        $sizeDefinition = AttributeDefinition::withoutWorkspaceScope()->where('code', 'size')->firstOrFail();

        $this->assertDatabaseHas('variant_attribute_values', [
            'variant_id' => $blueVariant->id,
            'attribute_definition_id' => $colorDefinition->id,
            'value_text' => 'blue',
        ]);

        $this->assertDatabaseHas('variant_attribute_values', [
            'variant_id' => $pinkVariant->id,
            'attribute_definition_id' => $colorDefinition->id,
            'value_text' => 'pink',
        ]);

        $this->assertDatabaseHas('variant_attribute_values', [
            'variant_id' => $sizeVariant->id,
            'attribute_definition_id' => $sizeDefinition->id,
            'value_text' => 'm',
        ]);

        $this->artisan('product-fields:migrate-legacy-attributes')
            ->assertSuccessful();

        $this->assertSame(3, VariantAttributeValue::withoutWorkspaceScope()->count());
    }
}
