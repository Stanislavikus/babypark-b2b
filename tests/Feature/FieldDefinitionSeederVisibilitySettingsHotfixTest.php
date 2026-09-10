<?php

namespace Tests\Feature;

use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FieldDefinitionSeederVisibilitySettingsHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_preserves_admin_customized_visibility_settings_on_existing_binding(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'status')
            ->firstOrFail();

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::Product)
            ->firstOrFail();

        $customVisibility = ['admin' => true, 'b2b' => true, 'channels' => []];
        $binding->update(['visibility_settings' => $customVisibility]);
        $binding->refresh();

        $snapshotBefore = $this->bindingSnapshot($binding);

        $this->seed(FieldDefinitionSeeder::class);

        $binding->refresh();

        $this->assertEquals($snapshotBefore, $this->bindingSnapshot($binding));
        $this->assertEquals($customVisibility, $binding->visibility_settings);
    }

    public function test_creates_new_binding_with_seed_default_visibility_settings(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $definition = FieldDefinition::withoutWorkspaceScope()
            ->where('code', 'battery_type')
            ->firstOrFail();

        FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->delete();

        $this->assertSame(
            0,
            FieldBinding::withoutWorkspaceScope()->where('field_definition_id', $definition->id)->count()
        );

        $this->seed(FieldDefinitionSeeder::class);

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', FieldObjectType::Product)
            ->firstOrFail();

        $this->assertEquals(
            ['admin' => true, 'b2b' => true, 'channels' => []],
            $binding->visibility_settings
        );
    }

    public function test_still_throws_on_structural_binding_conflict(): void
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
