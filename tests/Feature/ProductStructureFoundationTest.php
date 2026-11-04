<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\Workspace;
use App\Services\ProductStructure\BasicProductStructureReconciler;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductStructureFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function new_workspace_gets_exactly_one_basic_product_type(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace A']);

        $types = ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->get();

        $this->assertCount(1, $types);
        $this->assertTrue($types->first()->is_default);
        $this->assertSame('basic_product', $types->first()->code);
        $this->assertSame(1, $types->first()->structure_revision);
    }

    #[Test]
    public function product_created_without_type_automatically_uses_workspace_basic_product(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace A']);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'TYPE-DEFAULT-1',
            'name' => 'Default typed product',
            'is_active' => true,
        ]);

        $basic = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->sole();

        $this->assertSame($basic->id, $product->product_type_id);
        $this->assertSame($basic->id, $product->productType()->withoutGlobalScopes()->value('id'));
    }

    #[Test]
    public function basic_product_reconciler_places_only_admin_visible_product_and_variant_bindings(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace A']);
        $otherWorkspace = Workspace::query()->create(['name' => 'Workspace B']);

        $globalProduct = $this->binding(null, 'global_product', FieldObjectType::Product, 'characteristics', true);
        $workspaceVariant = $this->binding($workspace->id, 'workspace_variant', FieldObjectType::ProductVariant, 'characteristics', true);
        $foreignProduct = $this->binding($otherWorkspace->id, 'foreign_product', FieldObjectType::Product, 'characteristics', true);
        $customer = $this->binding($workspace->id, 'customer_contact', FieldObjectType::Customer, 'characteristics', true);
        $hidden = $this->binding($workspace->id, 'hidden_product', FieldObjectType::Product, 'internal', false);

        app(BasicProductStructureReconciler::class)->reconcileWorkspace($workspace->id);
        $basic = ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('is_default', true)->sole();
        $ids = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $basic->id)
            ->pluck('field_binding_id')
            ->all();

        $this->assertContains($globalProduct->id, $ids);
        $this->assertContains($workspaceVariant->id, $ids);
        $this->assertNotContains($foreignProduct->id, $ids);
        $this->assertNotContains($customer->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertCount(2, $ids);
    }

    #[Test]
    public function new_admin_visible_binding_is_automatically_reconciled_into_basic_product(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace A']);
        $basic = ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('is_default', true)->sole();
        $revisionBefore = $basic->structure_revision;

        $binding = $this->binding($workspace->id, 'warranty_period', FieldObjectType::Product, 'characteristics', true);

        $this->assertDatabaseHas('product_type_field_placements', [
            'workspace_id' => $workspace->id,
            'product_type_id' => $basic->id,
            'field_binding_id' => $binding->id,
        ]);
        $this->assertGreaterThan($revisionBefore, $basic->fresh()->structure_revision);
    }

    #[Test]
    public function database_rejects_second_default_product_type_for_same_workspace(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace A']);

        $this->expectException(QueryException::class);
        ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'second_default',
            'localized_labels' => ['en' => 'Second default'],
            'status' => 'active',
            'is_default' => true,
            'structure_revision' => 1,
        ]);
    }

    #[Test]
    public function manage_product_structure_permission_is_seeded(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        $this->assertDatabaseHas('workspace_permissions', [
            'code' => WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE,
        ]);
        $this->assertContains(WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE, WorkspacePermissions::catalogue());
    }

    private function binding(
        ?string $workspaceId,
        string $code,
        FieldObjectType $objectType,
        string $group,
        bool $adminVisible,
    ): FieldBinding {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'code' => $code,
            'data_type' => AttributeDataType::Text,
            'scope' => $workspaceId === null ? AttributeScope::PlatformLibrary : AttributeScope::WorkspaceCustom,
            'localized_labels' => ['en' => Str::headline($code)],
            'description' => null,
            'validation_rules' => null,
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'field_definition_id' => $definition->id,
            'object_type' => $objectType,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => $group,
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => $adminVisible, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
    }
}
