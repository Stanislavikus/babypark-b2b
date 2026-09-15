<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\AttributeGroup;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductStructure\ProductTypeForkService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductTypeForkServiceTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    #[Test]
    public function fork_from_basic_copies_structure_without_cloning_groups_definitions_or_bindings(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $binding = $this->binding($workspace->id, 'fork_field', 'characteristics');
        $basic = ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('is_default', true)->sole();

        $groupCount = AttributeGroup::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count();
        $definitionCount = FieldDefinition::withoutWorkspaceScope()->count();
        $bindingCount = FieldBinding::withoutWorkspaceScope()->count();

        $fork = app(ProductTypeForkService::class)->fromBasic($actor, $workspace, 'custom_from_basic', ['en' => 'Custom From Basic']);

        $this->assertFalse($fork->is_default);
        $this->assertSame(1, $fork->structure_revision);
        $sourceGroups = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $basic->id)
            ->orderBy('sort_order')
            ->get();
        $targetGroups = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $fork->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertSame(
            $sourceGroups->pluck('attribute_group_id')->all(),
            $targetGroups->pluck('attribute_group_id')->all(),
        );
        $this->assertSame(
            $sourceGroups->pluck('is_optional')->all(),
            $targetGroups->pluck('is_optional')->all(),
        );
        $this->assertSame(
            $sourceGroups->pluck('default_active')->all(),
            $targetGroups->pluck('default_active')->all(),
        );

        $sourceFields = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $basic->id)
            ->orderBy('sort_order')
            ->get();
        $targetFields = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $fork->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertContains($binding->id, $targetFields->pluck('field_binding_id')->all());
        $this->assertSame($sourceFields->pluck('field_binding_id')->all(), $targetFields->pluck('field_binding_id')->all());
        $this->assertSame($groupCount, AttributeGroup::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($definitionCount, FieldDefinition::withoutWorkspaceScope()->count());
        $this->assertSame($bindingCount, FieldBinding::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function later_basic_reconciliation_does_not_propagate_into_existing_fork(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $this->binding($workspace->id, 'fork_original', 'characteristics');
        $fork = app(ProductTypeForkService::class)->fromBasic($actor, $workspace, 'snapshot_type', ['en' => 'Snapshot Type']);
        $before = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $fork->id)
            ->pluck('field_binding_id')
            ->all();

        $newBinding = $this->binding($workspace->id, 'fork_later', 'characteristics');

        $basic = ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('is_default', true)->sole();
        $this->assertDatabaseHas('product_type_field_placements', [
            'product_type_id' => $basic->id,
            'field_binding_id' => $newBinding->id,
        ]);
        $this->assertSame(
            $before,
            ProductTypeFieldPlacement::withoutWorkspaceScope()
                ->where('product_type_id', $fork->id)
                ->pluck('field_binding_id')
                ->all(),
        );
        $this->assertDatabaseMissing('product_type_field_placements', [
            'product_type_id' => $fork->id,
            'field_binding_id' => $newBinding->id,
        ]);
    }

    #[Test]
    public function fork_requires_manage_product_structure_permission(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = Workspace::query()->where('is_default', true)->first()
            ?? Workspace::query()->create(['name' => 'Fork Workspace', 'is_default' => true]);
        $actor = User::factory()->create();
        $this->makeWorkspaceMembership($workspace, $actor);

        $this->expectException(AuthorizationException::class);
        app(ProductTypeForkService::class)->fromBasic($actor, $workspace, 'denied_fork', ['en' => 'Denied Fork']);
    }

    private function authorizedContext(): array
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = Workspace::query()->where('is_default', true)->first()
            ?? Workspace::query()->create(['name' => 'Fork Workspace', 'is_default' => true]);
        $actor = User::factory()->create();
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Fork Manager',
            [WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE],
        );
        $this->assignRoleToMembership($membership, $role);

        return [$workspace, $actor];
    }

    private function binding(string $workspaceId, string $code, string $group): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'code' => $code,
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['en' => Str::headline($code)],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => $group,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
    }
}
