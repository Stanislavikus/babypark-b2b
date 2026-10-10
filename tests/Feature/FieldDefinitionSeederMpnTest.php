<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use Database\Seeders\B2BSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDefinitionSeederMpnTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_SORT_ORDER = 95;

    public function test_mpn_seeded_with_expected_contract(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'mpn')
            ->firstOrFail();

        $this->assertSame(AttributeDataType::Text, $definition->data_type);
        $this->assertSame(AttributeScope::PlatformLibrary, $definition->scope);
        $this->assertFalse($definition->is_localizable);
        $this->assertFalse($definition->is_multi_value);
        $this->assertEquals(
            ['en' => 'Manufacturer Part Number', 'uk' => 'Артикул виробника', 'ru' => 'Артикул производителя'],
            $definition->localized_labels
        );

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::ProductVariant)
            ->firstOrFail();

        $this->assertSame(AttributeStorageType::Dynamic, $binding->storage_type);
        $this->assertNull($binding->storage_path);
        $this->assertSame('identifiers', $binding->field_group);
        $this->assertFalse($binding->is_required);
        $this->assertTrue($binding->is_filterable);
        $this->assertTrue($binding->is_sortable);
        $this->assertSame(self::EXPECTED_SORT_ORDER, $binding->sort_order);
    }

    public function test_mpn_seeder_is_idempotent(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $before = [
            'definitions' => FieldDefinition::withoutWorkspaceScope()->where('code', 'mpn')->count(),
            'bindings' => FieldBinding::withoutWorkspaceScope()
                ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'mpn'))
                ->count(),
        ];

        $snapshot = $this->mpnBindingSnapshot();

        $this->seed(FieldDefinitionSeeder::class);

        $this->assertSame($before['definitions'], FieldDefinition::withoutWorkspaceScope()->where('code', 'mpn')->count());
        $this->assertSame($before['bindings'], FieldBinding::withoutWorkspaceScope()
            ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'mpn'))
            ->count());
        $this->assertSame($snapshot, $this->mpnBindingSnapshot());
    }

    public function test_mpn_preserves_custom_sort_order_on_reseed(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $binding = $this->mpnBinding();
        $this->assertSame(self::EXPECTED_SORT_ORDER, $binding->sort_order);

        $binding->update(['sort_order' => 777]);
        $binding->refresh();

        $this->seed(FieldDefinitionSeeder::class);

        $binding->refresh();
        $this->assertSame(777, $binding->sort_order);
        $this->assertSame(1, FieldBinding::withoutWorkspaceScope()
            ->whereHas('fieldDefinition', fn ($q) => $q->where('code', 'mpn'))
            ->count());
    }

    public function test_duplicate_mpn_allowed_across_variants(): void
    {
        $this->seed(WorkspaceSeeder::class);
        $this->seed(B2BSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);

        $binding = $this->mpnBinding();
        $variants = ProductVariant::withoutWorkspaceScope()->limit(2)->get();

        $this->assertCount(2, $variants);

        foreach ($variants as $variant) {
            VariantFieldValue::withoutWorkspaceScope()->create([
                'workspace_id' => $variant->workspace_id,
                'variant_id' => $variant->id,
                'field_binding_id' => $binding->id,
                'value_text' => 'SHARED-MPN-12345',
            ]);
        }

        $this->assertSame(2, VariantFieldValue::withoutWorkspaceScope()
            ->where('field_binding_id', $binding->id)
            ->where('value_text', 'SHARED-MPN-12345')
            ->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function mpnBindingSnapshot(): array
    {
        $binding = $this->mpnBinding();

        return [
            'object_type' => $binding->object_type->value,
            'storage_type' => $binding->storage_type->value,
            'storage_path' => $binding->storage_path,
            'field_group' => $binding->field_group,
            'is_required' => $binding->is_required,
            'is_filterable' => $binding->is_filterable,
            'is_sortable' => $binding->is_sortable,
            'sort_order' => $binding->sort_order,
        ];
    }

    private function mpnBinding(): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'mpn')
            ->firstOrFail();

        return FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::ProductVariant)
            ->firstOrFail();
    }
}
