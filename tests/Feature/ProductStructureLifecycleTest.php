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
use App\Models\ProductFieldValue;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantFieldValue;
use App\Models\Workspace;
use App\Services\ProductStructure\ProductOptionalGroupMutationService;
use App\Services\ProductStructure\ProductOptionalGroupStateResolver;
use App\Services\ProductStructure\ProductStructureMutationService;
use App\Services\ProductStructure\ProductTypeBulkMutationService;
use App\Services\ProductStructure\ProductTypeChangeImpactService;
use App\Services\ProductStructure\ProductTypeMutationService;
use App\Support\ProductStructure\Exceptions\ProductStructureInvariantException;
use App\Support\ProductStructure\Exceptions\ProductTypeChangeStaleException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

class ProductStructureLifecycleTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    #[Test]
    public function type_change_preview_and_apply_preserve_values_and_remove_invalid_optional_overrides(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $productBinding = $this->binding($workspace->id, 'ps_product_text', FieldObjectType::Product);
        $variantBinding = $this->binding($workspace->id, 'ps_variant_text', FieldObjectType::ProductVariant);
        $product = $this->product($workspace);
        $variant = $this->variant($workspace, $product);
        ProductFieldValue::withoutWorkspaceScope()->create(['workspace_id' => $workspace->id, 'product_id' => $product->id, 'field_binding_id' => $productBinding->id, 'value_text' => 'KEEP-P']);
        VariantFieldValue::withoutWorkspaceScope()->create(['workspace_id' => $workspace->id, 'variant_id' => $variant->id, 'field_binding_id' => $variantBinding->id, 'value_text' => 'KEEP-V']);

        $structure = app(ProductStructureMutationService::class);
        $source = $structure->createProductType($actor, $workspace, 'source_type', ['en' => 'Source Type']);
        $sourceImpact = app(ProductTypeChangeImpactService::class)->preview($product, $source);
        app(ProductTypeMutationService::class)->change($actor, $workspace, $product, $source, $sourceImpact);
        $target = $structure->createProductType($actor, $workspace, 'target_type', ['en' => 'Target Type']);
        $targetGroup = $structure->createAttributeGroup($actor, $workspace, 'target_general', ['en' => 'Target General']);
        $targetPlacement = $structure->putGroupPlacement($actor, $workspace, $target, $targetGroup, 100, false, true);
        $structure->putFieldPlacement($actor, $workspace, $target, $targetPlacement, $productBinding, 100, true);

        $sourceGroup = $structure->createAttributeGroup($actor, $workspace, 'source_general', ['en' => 'Source General']);
        $sourcePlacement = $structure->putGroupPlacement($actor, $workspace, $source, $sourceGroup, 100, false, true);
        $structure->putFieldPlacement($actor, $workspace, $source, $sourcePlacement, $variantBinding, 100, false);

        $optionalGroup = $structure->createAttributeGroup($actor, $workspace, 'cellular', ['en' => 'Cellular']);
        $optionalPlacement = $structure->putGroupPlacement($actor, $workspace, $source, $optionalGroup, 900, true, false);
        app(ProductOptionalGroupMutationService::class)->setActive($actor, $workspace, $product, $optionalPlacement, true);

        $impact = app(ProductTypeChangeImpactService::class)->preview($product->fresh(), $target->fresh());
        $this->assertContains($variantBinding->id, $impact->removedBindingIds);
        $this->assertContains($productBinding->id, $impact->newlyRequiredBindingIds);
        $this->assertContains(['variant_id' => $variant->id, 'field_binding_id' => $variantBinding->id], $impact->outOfTypeVariantCells);
        $this->assertCount(1, $impact->invalidOptionalOverrideIds);
        $this->assertSame('deferred_to_slice_b', $impact->completenessProjectionStatus);
        $this->assertNull($impact->completenessBefore);
        $this->assertNull($impact->completenessAfter);

        $result = app(ProductTypeMutationService::class)->change($actor, $workspace, $product, $target, $impact);

        $this->assertSame($workspace->id, $result->workspaceId);
        $this->assertSame($actor->id, $result->actorId);
        $this->assertSame($product->id, $result->productId);
        $this->assertSame($impact->fromProductTypeId, $result->fromProductTypeId);
        $this->assertSame($target->id, $result->toProductTypeId);
        $this->assertSame($impact->fingerprint(), $result->impactFingerprint);
        $this->assertSame(1, $result->invalidOptionalOverridesRemoved);
        $this->assertSame($target->id, $product->fresh()->product_type_id);
        $this->assertSame('KEEP-P', ProductFieldValue::withoutWorkspaceScope()->where('product_id', $product->id)->where('field_binding_id', $productBinding->id)->sole()->value_text);
        $this->assertSame('KEEP-V', VariantFieldValue::withoutWorkspaceScope()->where('variant_id', $variant->id)->where('field_binding_id', $variantBinding->id)->sole()->value_text);
        $this->assertDatabaseMissing('product_active_optional_groups', ['product_id' => $product->id]);
    }

    #[Test]
    public function stale_structure_revision_rejects_apply_without_changing_product(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $product = $this->product($workspace);
        $structure = app(ProductStructureMutationService::class);
        $target = $structure->createProductType($actor, $workspace, 'stale_target', ['en' => 'Stale Target']);
        $impact = app(ProductTypeChangeImpactService::class)->preview($product, $target);
        $group = $structure->createAttributeGroup($actor, $workspace, 'late_group', ['en' => 'Late Group']);
        $structure->putGroupPlacement($actor, $workspace, $target, $group, 100, false, true);

        try {
            app(ProductTypeMutationService::class)->change($actor, $workspace, $product, $target, $impact);
            $this->fail('Expected stale preview rejection.');
        } catch (ProductTypeChangeStaleException) {
            $this->assertSame($impact->fromProductTypeId, $product->fresh()->product_type_id);
        }
    }

    #[Test]
    public function optional_group_override_uses_default_and_rejects_required_group_mutation(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $product = $this->product($workspace);
        $structure = app(ProductStructureMutationService::class);
        $type = $structure->createProductType($actor, $workspace, 'optional_type', ['en' => 'Optional Type']);
        $typeImpact = app(ProductTypeChangeImpactService::class)->preview($product, $type);
        app(ProductTypeMutationService::class)->change($actor, $workspace, $product, $type, $typeImpact);
        $group = $structure->createAttributeGroup($actor, $workspace, 'optional_test', ['en' => 'Optional Test']);
        $optional = $structure->putGroupPlacement($actor, $workspace, $type, $group, 500, true, false);
        $resolver = app(ProductOptionalGroupStateResolver::class);

        $this->assertFalse($resolver->isActive($product, $optional));
        app(ProductOptionalGroupMutationService::class)->setActive($actor, $workspace, $product, $optional, true);
        $this->assertTrue($resolver->isActive($product, $optional));
        $this->assertDatabaseHas('product_active_optional_groups', ['product_id' => $product->id, 'product_type_group_placement_id' => $optional->id, 'is_active' => true]);
        app(ProductOptionalGroupMutationService::class)->setActive($actor, $workspace, $product, $optional, false);
        $this->assertDatabaseMissing('product_active_optional_groups', ['product_id' => $product->id, 'product_type_group_placement_id' => $optional->id]);

        $requiredGroup = $structure->createAttributeGroup($actor, $workspace, 'required_test', ['en' => 'Required Test']);
        $required = $structure->putGroupPlacement($actor, $workspace, $type, $requiredGroup, 600, false, false);
        $this->expectException(ProductStructureInvariantException::class);
        app(ProductOptionalGroupMutationService::class)->setActive($actor, $workspace, $product, $required, false);
    }

    #[Test]
    public function structure_revision_bumps_only_for_semantic_child_change(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $structure = app(ProductStructureMutationService::class);
        $type = $structure->createProductType($actor, $workspace, 'revision_type', ['en' => 'Revision Type']);
        $group = $structure->createAttributeGroup($actor, $workspace, 'revision_group', ['en' => 'Revision Group']);
        $before = $type->fresh()->structure_revision;
        $placement = $structure->putGroupPlacement($actor, $workspace, $type, $group, 100, true, false);
        $afterCreate = $type->fresh()->structure_revision;
        $structure->putGroupPlacement($actor, $workspace, $type, $group, 100, true, false);
        $afterNoOp = $type->fresh()->structure_revision;
        $structure->putGroupPlacement($actor, $workspace, $type, $group, 200, true, false);

        $this->assertSame($before + 1, $afterCreate);
        $this->assertSame($afterCreate, $afterNoOp);
        $this->assertSame($afterCreate + 1, $type->fresh()->structure_revision);
        $this->assertSame(200, $placement->fresh()->sort_order);
    }

    #[Test]
    public function bulk_type_change_reports_per_product_partial_failure(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $first = $this->product($workspace);
        $second = $this->product($workspace);
        $structure = app(ProductStructureMutationService::class);
        $target = $structure->createProductType($actor, $workspace, 'bulk_target', ['en' => 'Bulk Target']);
        $impactService = app(ProductTypeChangeImpactService::class);
        $firstImpact = $impactService->preview($first, $target);
        $secondImpact = $impactService->preview($second, $target);

        $other = $structure->createProductType($actor, $workspace, 'intervening_type', ['en' => 'Intervening']);
        $secondFreshImpact = $impactService->preview($second, $other);
        app(ProductTypeMutationService::class)->change($actor, $workspace, $second, $other, $secondFreshImpact);

        $result = app(ProductTypeBulkMutationService::class)->changeMany($actor, $workspace, $target, [$firstImpact, $secondImpact]);

        $this->assertSame([$first->id], $result['succeeded_product_ids']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($second->id, $result['failed'][0]['product_id']);
        $this->assertSame($target->id, $first->fresh()->product_type_id);
        $this->assertSame($other->id, $second->fresh()->product_type_id);
    }

    #[Test]
    public function field_placement_change_bumps_revision_and_noop_does_not(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $binding = $this->binding($workspace->id, 'revision_field', FieldObjectType::Product);
        $structure = app(ProductStructureMutationService::class);
        $type = $structure->createProductType($actor, $workspace, 'field_revision_type', ['en' => 'Field Revision']);
        $group = $structure->createAttributeGroup($actor, $workspace, 'field_revision_group', ['en' => 'Field Revision Group']);
        $groupPlacement = $structure->putGroupPlacement($actor, $workspace, $type, $group, 100, false, true);
        $before = $type->fresh()->structure_revision;

        $structure->putFieldPlacement($actor, $workspace, $type, $groupPlacement, $binding, 100, false);
        $afterCreate = $type->fresh()->structure_revision;
        $structure->putFieldPlacement($actor, $workspace, $type, $groupPlacement, $binding, 100, false);
        $afterNoOp = $type->fresh()->structure_revision;
        $structure->putFieldPlacement($actor, $workspace, $type, $groupPlacement, $binding, 100, true);

        $this->assertSame($before + 1, $afterCreate);
        $this->assertSame($afterCreate, $afterNoOp);
        $this->assertSame($afterCreate + 1, $type->fresh()->structure_revision);
    }

    #[Test]
    public function removing_group_placement_is_governed_and_bumps_structure_revision(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $structure = app(ProductStructureMutationService::class);
        $type = $structure->createProductType($actor, $workspace, 'remove_group_type', ['en' => 'Remove Group']);
        $group = $structure->createAttributeGroup($actor, $workspace, 'remove_group', ['en' => 'Remove Me']);
        $placement = $structure->putGroupPlacement($actor, $workspace, $type, $group, 100, true, false);
        $before = $type->fresh()->structure_revision;

        $structure->removeGroupPlacement($actor, $workspace, $placement);

        $this->assertSame($before + 1, $type->fresh()->structure_revision);
        $this->assertDatabaseMissing('product_type_group_placements', ['id' => $placement->id]);
    }

    #[Test]
    public function basic_product_rejects_all_merchant_structural_mutations(): void
    {
        [$workspace, $actor] = $this->authorizedContext();
        $structure = app(ProductStructureMutationService::class);
        $binding = $this->binding($workspace->id, 'basic_guard_field', FieldObjectType::Product);
        $basic = ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('is_default', true)->sole();
        $fieldPlacement = ProductTypeFieldPlacement::withoutWorkspaceScope()
            ->where('product_type_id', $basic->id)
            ->where('field_binding_id', $binding->id)
            ->sole();
        $groupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()->findOrFail($fieldPlacement->product_type_group_placement_id);
        $merchantGroup = $structure->createAttributeGroup($actor, $workspace, 'merchant_group', ['en' => 'Merchant Group']);
        $revision = $basic->structure_revision;

        foreach ([
            'put group placement' => fn () => $structure->putGroupPlacement($actor, $workspace, $basic, $merchantGroup, 500, true, false),
            'put field placement' => fn () => $structure->putFieldPlacement($actor, $workspace, $basic, $groupPlacement, $binding, 500, true),
            'remove field placement' => fn () => $structure->removeFieldPlacement($actor, $workspace, $fieldPlacement),
            'remove group placement' => fn () => $structure->removeGroupPlacement($actor, $workspace, $groupPlacement),
        ] as $label => $mutation) {
            try {
                $mutation();
                $this->fail("Expected Basic Product {$label} to be rejected.");
            } catch (ProductStructureInvariantException $exception) {
                $this->assertStringContainsString('system-managed', $exception->getMessage(), $label);
            }
        }

        $this->assertSame($revision, $basic->fresh()->structure_revision);
        $this->assertDatabaseHas('product_type_field_placements', ['id' => $fieldPlacement->id]);
        $this->assertDatabaseHas('product_type_group_placements', ['id' => $groupPlacement->id]);
        $this->assertDatabaseMissing('product_type_group_placements', [
            'product_type_id' => $basic->id,
            'attribute_group_id' => $merchantGroup->id,
        ]);
    }

    #[Test]
    public function structure_mutation_requires_atomic_permission(): void
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = Workspace::query()->create(['name' => 'Unauthorized Workspace']);
        $actor = User::factory()->create();
        $this->makeWorkspaceMembership($workspace, $actor);

        $this->expectException(AuthorizationException::class);
        app(ProductStructureMutationService::class)->createProductType($actor, $workspace, 'denied', ['en' => 'Denied']);
    }

    private function authorizedContext(): array
    {
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = Workspace::query()->create(['name' => 'Structure Workspace']);
        $actor = User::factory()->create();
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions($workspace->id, 'Structure Manager', [WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE]);
        $this->assignRoleToMembership($membership, $role);

        return [$workspace, $actor];
    }

    private function product(Workspace $workspace): Product
    {
        return Product::withoutWorkspaceScope()->create(['workspace_id' => $workspace->id, 'onec_guid' => (string) Str::uuid(), 'sku' => 'P-'.Str::random(8), 'name' => 'Structure Product', 'is_active' => true]);
    }

    private function variant(Workspace $workspace, Product $product): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create(['workspace_id' => $workspace->id, 'product_id' => $product->id, 'onec_guid' => (string) Str::uuid(), 'sku' => 'V-'.Str::random(8), 'is_active' => true]);
    }

    private function binding(string $workspaceId, string $code, FieldObjectType $objectType): FieldBinding
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->create(['workspace_id' => $workspaceId, 'code' => $code, 'data_type' => AttributeDataType::Text, 'scope' => AttributeScope::WorkspaceCustom, 'localized_labels' => ['en' => Str::headline($code)], 'is_localizable' => false, 'is_multi_value' => false, 'status' => AttributeStatus::Active]);

        return FieldBinding::withoutWorkspaceScope()->create(['workspace_id' => $workspaceId, 'field_definition_id' => $definition->id, 'object_type' => $objectType, 'storage_type' => AttributeStorageType::Dynamic, 'field_group' => 'characteristics', 'is_required' => false, 'is_filterable' => false, 'is_sortable' => false, 'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []], 'sort_order' => 100, 'status' => AttributeStatus::Active]);
    }
}
