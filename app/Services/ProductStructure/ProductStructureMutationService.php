<?php

namespace App\Services\ProductStructure;

use App\Enums\AttributeStatus;
use App\Enums\FieldObjectType;
use App\Models\AttributeGroup;
use App\Models\FieldBinding;
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\ProductStructure\Exceptions\ProductStructureInvariantException;
use App\Support\ProductStructure\Exceptions\ProductStructureStaleException;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ProductStructureMutationService
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function createProductType(User $actor, Workspace $workspace, string $code, array $labels, ?string $description = null): ProductType
    {
        return DB::transaction(function () use ($actor, $workspace, $code, $labels, $description): ProductType {
            $lockedWorkspace = $this->lockedWorkspace($actor, $workspace);

            return ProductType::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'code' => $code,
                'localized_labels' => $labels,
                'description' => $description,
                'status' => 'active',
                'is_default' => false,
                'structure_revision' => 1,
            ]);
        });
    }

    public function createAttributeGroup(User $actor, Workspace $workspace, string $code, array $labels, ?string $description = null): AttributeGroup
    {
        return DB::transaction(function () use ($actor, $workspace, $code, $labels, $description): AttributeGroup {
            $lockedWorkspace = $this->lockedWorkspace($actor, $workspace);

            return AttributeGroup::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'code' => $code,
                'localized_labels' => $labels,
                'description' => $description,
                'status' => 'active',
            ]);
        });
    }

    public function putGroupPlacement(
        User $actor,
        Workspace $workspace,
        ProductType $type,
        AttributeGroup $group,
        int $sortOrder,
        bool $optional,
        bool $defaultActive,
        ?int $expectedStructureRevision = null,
    ): ProductTypeGroupPlacement {
        return DB::transaction(function () use ($actor, $workspace, $type, $group, $sortOrder, $optional, $defaultActive, $expectedStructureRevision): ProductTypeGroupPlacement {
            $this->lockedWorkspace($actor, $workspace);
            $lockedType = $this->lockedType($workspace, $type->id);
            $this->assertMerchantEditableType($lockedType);
            $this->assertExpectedRevision($lockedType, $expectedStructureRevision);
            $lockedGroup = AttributeGroup::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $defaultActive = $optional ? $defaultActive : true;

            $placement = ProductTypeGroupPlacement::withoutWorkspaceScope()
                ->where('product_type_id', $lockedType->id)
                ->where('attribute_group_id', $lockedGroup->id)
                ->lockForUpdate()
                ->first() ?? new ProductTypeGroupPlacement;

            $placement->fill([
                'workspace_id' => $workspace->id,
                'product_type_id' => $lockedType->id,
                'attribute_group_id' => $lockedGroup->id,
                'sort_order' => $sortOrder,
                'is_optional' => $optional,
                'default_active' => $defaultActive,
            ]);

            if (! $placement->exists || $placement->isDirty()) {
                $placement->save();
                $this->bumpRevision($lockedType);
            }

            return $placement->fresh();
        });
    }

    public function putFieldPlacement(
        User $actor,
        Workspace $workspace,
        ProductType $type,
        ProductTypeGroupPlacement $groupPlacement,
        FieldBinding $binding,
        int $sortOrder,
        bool $requiredForCompleteness,
        ?int $expectedStructureRevision = null,
    ): ProductTypeFieldPlacement {
        return DB::transaction(function () use ($actor, $workspace, $type, $groupPlacement, $binding, $sortOrder, $requiredForCompleteness, $expectedStructureRevision): ProductTypeFieldPlacement {
            $this->lockedWorkspace($actor, $workspace);
            $lockedType = $this->lockedType($workspace, $type->id);
            $this->assertMerchantEditableType($lockedType);
            $this->assertExpectedRevision($lockedType, $expectedStructureRevision);
            $lockedGroupPlacement = ProductTypeGroupPlacement::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('product_type_id', $lockedType->id)
                ->whereKey($groupPlacement->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedBinding = FieldBinding::withoutWorkspaceScope()->whereKey($binding->id)->lockForUpdate()->firstOrFail();
            $this->assertBindingAllowed($workspace, $lockedBinding);

            $placement = ProductTypeFieldPlacement::withoutWorkspaceScope()
                ->where('product_type_id', $lockedType->id)
                ->where('field_binding_id', $lockedBinding->id)
                ->lockForUpdate()
                ->first() ?? new ProductTypeFieldPlacement;

            $placement->fill([
                'workspace_id' => $workspace->id,
                'product_type_id' => $lockedType->id,
                'product_type_group_placement_id' => $lockedGroupPlacement->id,
                'field_binding_id' => $lockedBinding->id,
                'sort_order' => $sortOrder,
                'required_for_completeness' => $requiredForCompleteness,
            ]);

            if (! $placement->exists || $placement->isDirty()) {
                $placement->save();
                $this->bumpRevision($lockedType);
            }

            return $placement->fresh();
        });
    }

    public function removeGroupPlacement(User $actor, Workspace $workspace, ProductTypeGroupPlacement $placement, ?int $expectedStructureRevision = null): void
    {
        DB::transaction(function () use ($actor, $workspace, $placement, $expectedStructureRevision): void {
            $this->lockedWorkspace($actor, $workspace);
            $locked = ProductTypeGroupPlacement::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($placement->id)
                ->lockForUpdate()
                ->firstOrFail();
            $type = $this->lockedType($workspace, $locked->product_type_id);
            $this->assertMerchantEditableType($type);
            $this->assertExpectedRevision($type, $expectedStructureRevision);
            $locked->delete();
            $this->bumpRevision($type);
        });
    }

    public function removeFieldPlacement(User $actor, Workspace $workspace, ProductTypeFieldPlacement $placement, ?int $expectedStructureRevision = null): void
    {
        DB::transaction(function () use ($actor, $workspace, $placement, $expectedStructureRevision): void {
            $this->lockedWorkspace($actor, $workspace);
            $locked = ProductTypeFieldPlacement::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($placement->id)->lockForUpdate()->firstOrFail();
            $type = $this->lockedType($workspace, $locked->product_type_id);
            $this->assertMerchantEditableType($type);
            $this->assertExpectedRevision($type, $expectedStructureRevision);
            $locked->delete();
            $this->bumpRevision($type);
        });
    }

    public function updateProductTypePresentation(
        User $actor,
        Workspace $workspace,
        ProductType $type,
        array $labels,
        ?string $description,
    ): ProductType {
        return DB::transaction(function () use ($actor, $workspace, $type, $labels, $description): ProductType {
            $this->lockedWorkspace($actor, $workspace);
            $lockedType = $this->lockedType($workspace, $type->id);
            $this->assertMerchantEditableType($lockedType);
            $lockedType->localized_labels = $labels;
            $lockedType->description = $description;
            if ($lockedType->isDirty()) {
                $lockedType->save();
            }

            return $lockedType->fresh();
        });
    }

    public function updateAttributeGroupPresentation(
        User $actor,
        Workspace $workspace,
        AttributeGroup $group,
        array $labels,
        ?string $description,
    ): AttributeGroup {
        return DB::transaction(function () use ($actor, $workspace, $group, $labels, $description): AttributeGroup {
            $this->lockedWorkspace($actor, $workspace);
            $lockedGroup = AttributeGroup::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereKey($group->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedGroup->localized_labels = $labels;
            $lockedGroup->description = $description;
            if ($lockedGroup->isDirty()) {
                $lockedGroup->save();
            }

            return $lockedGroup->fresh();
        });
    }

    private function lockedWorkspace(User $actor, Workspace $workspace): Workspace
    {
        $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
        if (! $this->authorization->allows($actor, $locked, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $locked;
    }

    private function lockedType(Workspace $workspace, string $typeId): ProductType
    {
        return ProductType::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($typeId)->lockForUpdate()->firstOrFail();
    }

    private function assertMerchantEditableType(ProductType $type): void
    {
        if ($type->is_default) {
            throw new ProductStructureInvariantException('Basic Product structure is system-managed and cannot be edited directly.');
        }
    }

    private function assertExpectedRevision(ProductType $type, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && $type->structure_revision !== $expectedRevision) {
            throw ProductStructureStaleException::revisionMismatch();
        }
    }

    private function assertBindingAllowed(Workspace $workspace, FieldBinding $binding): void
    {
        if ($binding->workspace_id !== null && (string) $binding->workspace_id !== (string) $workspace->id) {
            throw new ProductStructureInvariantException('FieldBinding belongs to another workspace.');
        }
        if (! in_array($binding->object_type, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            throw new ProductStructureInvariantException('Only Product and ProductVariant bindings may be placed in ProductType.');
        }
        if ($binding->status !== AttributeStatus::Active) {
            throw new ProductStructureInvariantException('Only active FieldBindings may be placed in ProductType.');
        }
    }

    private function bumpRevision(ProductType $type): void
    {
        ProductType::withoutWorkspaceScope()->whereKey($type->id)->increment('structure_revision');
    }
}
