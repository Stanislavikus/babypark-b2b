<?php

namespace Tests\Feature;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FieldDefinitionSeederBindingFlagsHotfixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function adminEditableBindingFieldProvider(): array
    {
        return [
            'is_required' => ['is_required', 'status', ['is_required' => true]],
            'is_filterable' => ['is_filterable', 'status', ['is_filterable' => false]],
            'is_sortable' => ['is_sortable', 'status', ['is_sortable' => false]],
            'sort_order' => ['sort_order', 'color', ['sort_order' => 999]],
        ];
    }

    #[DataProvider('adminEditableBindingFieldProvider')]
    public function test_preserves_admin_customized_binding_field(
        string $field,
        string $definitionCode,
        array $customValues,
    ): void {
        $this->seed(FieldDefinitionSeeder::class);

        $objectType = $definitionCode === 'color'
            ? FieldObjectType::ProductVariant
            : FieldObjectType::Product;

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', $definitionCode)
            ->firstOrFail();

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', $objectType)
            ->firstOrFail();

        $binding->update($customValues);
        $binding->refresh();

        $snapshotBefore = $this->bindingSnapshot($binding);

        $this->seed(FieldDefinitionSeeder::class);

        $binding->refresh();

        $this->assertSame($snapshotBefore, $this->bindingSnapshot($binding));
        $this->assertSame($customValues[$field], $binding->{$field});
    }

    public function test_creates_new_binding_with_seed_default_binding_flags(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'battery_type')
            ->firstOrFail();

        FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->delete();

        $this->seed(FieldDefinitionSeeder::class);

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::Product)
            ->firstOrFail();

        $this->assertFalse($binding->is_required);
        $this->assertTrue($binding->is_filterable);
        $this->assertFalse($binding->is_sortable);
        $this->assertSame(190, $binding->sort_order);
    }

    public function test_still_throws_on_storage_path_conflict(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'status')
            ->firstOrFail();

        FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::Product)
            ->update(['storage_path' => 'products.wrong_column']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Field binding conflict for \'status\' (product):');
        $this->expectExceptionMessage('storage_path expected');

        $this->seed(FieldDefinitionSeeder::class);
    }

    public function test_still_throws_on_field_group_conflict(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'color')
            ->firstOrFail();

        FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::ProductVariant)
            ->update(['field_group' => 'wrong_group']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Field binding conflict for \'color\' (product_variant):');
        $this->expectExceptionMessage('field_group expected');

        $this->seed(FieldDefinitionSeeder::class);
    }

    public function test_still_throws_on_status_conflict(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'color')
            ->firstOrFail();

        FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::ProductVariant)
            ->update(['status' => AttributeStatus::Archived]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Field binding conflict for \'color\' (product_variant):');
        $this->expectExceptionMessage('status expected');

        $this->seed(FieldDefinitionSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingSnapshot(FieldBinding $binding): array
    {
        return [
            'workspace_id' => $binding->workspace_id,
            'field_definition_id' => $binding->field_definition_id,
            'object_type' => $binding->object_type->value,
            'storage_type' => $binding->storage_type->value,
            'storage_path' => $binding->storage_path,
            'field_group' => $binding->field_group,
            'is_required' => $binding->is_required,
            'is_filterable' => $binding->is_filterable,
            'is_sortable' => $binding->is_sortable,
            'visibility_settings' => $binding->visibility_settings,
            'sort_order' => $binding->sort_order,
            'status' => $binding->status->value,
        ];
    }
}
