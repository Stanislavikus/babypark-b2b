<?php

namespace Tests\Feature;

use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use Database\Seeders\B2BSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateLegacyProductVariantAttributesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(B2BSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);
    }

    public function test_command_migrates_legacy_variant_attributes_json(): void
    {
        $variant = ProductVariant::withoutWorkspaceScope()->firstOrFail();
        $variant->update([
            'attributes' => [
                'Колір' => 'Синій',
                'Розмір' => 'M',
            ],
        ]);

        $this->artisan('product-fields:migrate-legacy-attributes')
            ->assertSuccessful();

        $this->assertSame(2, VariantFieldValue::withoutWorkspaceScope()
            ->where('variant_id', $variant->id)
            ->count());

        $colorBinding = FieldBinding::withoutWorkspaceScope()
            ->where('object_type', FieldObjectType::ProductVariant)
            ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'color'))
            ->firstOrFail();

        $sizeBinding = FieldBinding::withoutWorkspaceScope()
            ->where('object_type', FieldObjectType::ProductVariant)
            ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'size'))
            ->firstOrFail();

        $this->assertDatabaseHas('variant_field_values', [
            'variant_id' => $variant->id,
            'field_binding_id' => $colorBinding->id,
            'value_text' => 'blue',
        ]);

        $this->assertDatabaseHas('variant_field_values', [
            'variant_id' => $variant->id,
            'field_binding_id' => $sizeBinding->id,
            'value_text' => 'm',
        ]);
    }

    public function test_dry_run_does_not_persist_values(): void
    {
        $variant = ProductVariant::withoutWorkspaceScope()->firstOrFail();
        $variant->update(['attributes' => ['Колір' => 'Синій']]);

        $this->artisan('product-fields:migrate-legacy-attributes', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, VariantFieldValue::withoutWorkspaceScope()->count());
    }
}
