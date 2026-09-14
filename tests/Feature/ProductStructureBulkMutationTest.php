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
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\ProductStructure\ProductOptionalGroupBulkMutationService;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Services\ProductStructure\ProductVariantBulkValueMutationService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductStructureBulkMutationTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    #[Test]
    public function optional_group_bulk_is_per_product_and_reports_partial_failures(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $structure = app(ProductStructureMutationService::class);
        $typeWithGroup = $structure->createProductType($actor, $workspace, 'with-extras', ['uk' => 'З додатковими']);
        $typeWithoutGroup = $structure->createProductType($actor, $workspace, 'without-extras', ['uk' => 'Без додаткових']);
        $group = $structure->createAttributeGroup($actor, $workspace, 'extras', ['uk' => 'Додаткові']);
        $placement = $structure->putGroupPlacement($actor, $workspace, $typeWithGroup, $group, 100, true, false);

        $first = $this->product($workspace, 'BULK-OPT-1', $typeWithGroup);
        $second = $this->product($workspace, 'BULK-OPT-2', $typeWithoutGroup);

        $result = app(ProductOptionalGroupBulkMutationService::class)->setActiveMany(
            $actor,
            $workspace,
            [$second->id, $first->id],
            $group->id,
            true,
        );

        $this->assertSame([$first->id], $result['succeeded_product_ids']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($second->id, $result['failed'][0]['product_id']);
        $this->assertDatabaseHas('product_active_optional_groups', [
            'workspace_id' => $workspace->id,
            'product_id' => $first->id,
            'product_type_group_placement_id' => $placement->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function variant_bulk_uses_governed_writer_and_reports_non_sibling_failure(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $type = ProductType::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'variant-bulk',
            'localized_labels' => ['uk' => 'Variant bulk'],
            'status' => 'active',
            'is_default' => false,
            'structure_revision' => 0,
        ]);
        $group = AttributeGroup::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'variant-bulk-group',
            'localized_labels' => ['uk' => 'Варіанти'],
            'status' => 'active',
        ]);
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_type_id' => $type->id,
            'attribute_group_id' => $group->id,
            'sort_order' => 10,
            'is_optional' => false,
            'default_active' => true,
        ]);
        $binding = $this->variantSelectBinding($workspace);
        ProductTypeFieldPlacement::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_type_id' => $type->id,
            'product_type_group_placement_id' => $groupPlacement->id,
            'field_binding_id' => $binding->id,
            'sort_order' => 10,
            'required_for_completeness' => true,
        ]);

        $product = $this->product($workspace, 'BULK-VAR-1', $type);
        $first = $this->variant($workspace, $product, 'BULK-VAR-1-A');
        $second = $this->variant($workspace, $product, 'BULK-VAR-1-B');
        $otherProduct = $this->product($workspace, 'BULK-VAR-2', $type);
        $foreign = $this->variant($workspace, $otherProduct, 'BULK-VAR-2-A');

        $result = app(ProductVariantBulkValueMutationService::class)->apply(
            $product,
            $binding,
            [$foreign->id, $second->id, $first->id],
            'blue',
        );

        $this->assertSame([$first->id, $second->id], $result['succeeded_variant_ids']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($foreign->id, $result['failed'][0]['variant_id']);
        foreach ([$first, $second] as $variant) {
            $this->assertSame('blue', VariantFieldValue::withoutWorkspaceScope()
                ->where('variant_id', $variant->id)
                ->where('field_binding_id', $binding->id)
                ->sole()->value_text);
        }

        $invalid = app(ProductVariantBulkValueMutationService::class)->apply(
            $product,
            $binding,
            [$first->id, $second->id],
            'not-declared',
        );
        $this->assertSame([], $invalid['succeeded_variant_ids']);
        $this->assertCount(2, $invalid['failed']);
        $this->assertSame(2, VariantFieldValue::withoutWorkspaceScope()
            ->whereIn('variant_id', [$first->id, $second->id])
            ->where('field_binding_id', $binding->id)
            ->where('value_text', 'blue')
            ->count());
    }

    private function authorizedContext(): array
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = Workspace::query()->where('is_default', true)->sole();
        $actor = User::factory()->create(['is_active' => true]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions(
            $workspace->id,
            'Bulk Structure Manager',
            [WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE],
        );
        $this->assignRoleToMembership($membership, $role);

        return [$workspace, $actor];
    }

    private function product(Workspace $workspace, string $sku, ProductType $type): Product
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
        ]);
        Product::withoutWorkspaceScope()->whereKey($product->id)->update(['product_type_id' => $type->id]);

        return $product->fresh();
    }

    private function variant(Workspace $workspace, Product $product, string $sku): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function variantSelectBinding(Workspace $workspace): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'code' => 'bulk_variant_color',
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::WorkspaceCustom,
            'localized_labels' => ['uk' => 'Колір'],
            'validation_rules' => ['options' => [
                ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                ['code' => 'red', 'labels' => ['uk' => 'Червоний']],
            ]],
            'is_localizable' => false,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        return FieldBinding::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'field_definition_id' => $definition->id,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 100,
            'status' => AttributeStatus::Active,
        ]);
    }
}
