<?php

namespace Tests\Feature;

use App\Models\AttributeGroup;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductStructurePolicyTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    #[Test]
    public function manage_product_structure_permission_controls_collection_and_create_access(): void
    {
        [$workspace, $manager, $viewer] = $this->policyContext();
        app(WorkspaceContext::class)->reset();

        $this->assertTrue(Gate::forUser($manager)->allows('viewAny', ProductType::class));
        $this->assertTrue(Gate::forUser($manager)->allows('create', ProductType::class));
        $this->assertTrue(Gate::forUser($manager)->allows('viewAny', AttributeGroup::class));
        $this->assertTrue(Gate::forUser($manager)->allows('create', AttributeGroup::class));

        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', ProductType::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', ProductType::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', AttributeGroup::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', AttributeGroup::class));
        $this->assertTrue($workspace->is_default);
    }

    #[Test]
    public function basic_product_is_viewable_but_not_updateable_or_deletable(): void
    {
        [$workspace, $manager] = $this->policyContext();
        $basic = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->sole();

        $this->assertTrue(Gate::forUser($manager)->allows('view', $basic));
        $this->assertFalse(Gate::forUser($manager)->allows('update', $basic));
        $this->assertFalse(Gate::forUser($manager)->allows('delete', $basic));
        $this->assertFalse(Gate::forUser($manager)->allows('restore', $basic));
        $this->assertFalse(Gate::forUser($manager)->allows('forceDelete', $basic));
    }

    #[Test]
    public function custom_type_and_shared_group_are_updateable_but_not_deletable(): void
    {
        [$workspace, $manager] = $this->policyContext();
        $type = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'policy_custom',
            'localized_labels' => ['en' => 'Policy Custom'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 1,
        ]);
        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'policy_group',
            'localized_labels' => ['en' => 'Policy Group'],
            'status' => 'active',
        ]);

        $this->assertTrue(Gate::forUser($manager)->allows('update', $type));
        $this->assertFalse(Gate::forUser($manager)->allows('delete', $type));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $group));
        $this->assertFalse(Gate::forUser($manager)->allows('delete', $group));
    }

    private function policyContext(): array
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = Workspace::query()->where('is_default', true)->first()
            ?? Workspace::query()->create(['name' => 'Policy Workspace', 'is_default' => true]);
        app(WorkspaceContext::class)->reset();

        $manager = User::factory()->create();
        $membership = $this->makeWorkspaceMembership($workspace, $manager);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Structure Manager',
            [WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE],
        );
        $this->assignRoleToMembership($membership, $role);

        $viewer = User::factory()->create();
        $this->makeWorkspaceMembership($workspace, $viewer);

        return [$workspace, $manager, $viewer];
    }
}
