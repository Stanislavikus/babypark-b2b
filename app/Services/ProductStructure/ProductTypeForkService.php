<?php

namespace App\Services\ProductStructure;

use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ProductTypeForkService
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    public function fromBasic(
        User $actor,
        Workspace $workspace,
        string $code,
        array $labels,
    ): ProductType {
        return DB::transaction(function () use ($actor, $workspace, $code, $labels): ProductType {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCT_STRUCTURE)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $basic = ProductType::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->sole();

            $target = ProductType::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'code' => $code,
                'localized_labels' => $labels,
                'description' => null,
                'status' => 'active',
                'is_default' => false,
                'structure_revision' => 1,
            ]);

            $sourceGroups = ProductTypeGroupPlacement::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_type_id', $basic->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($sourceGroups as $sourceGroup) {
                $targetGroup = ProductTypeGroupPlacement::withoutWorkspaceScope()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    'product_type_id' => $target->id,
                    'attribute_group_id' => $sourceGroup->attribute_group_id,
                    'sort_order' => $sourceGroup->sort_order,
                    'is_optional' => $sourceGroup->is_optional,
                    'default_active' => $sourceGroup->default_active,
                ]);

                $sourceFields = ProductTypeFieldPlacement::withoutWorkspaceScope()
                    ->where('workspace_id', $lockedWorkspace->id)
                    ->where('product_type_id', $basic->id)
                    ->where('product_type_group_placement_id', $sourceGroup->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($sourceFields as $sourceField) {
                    ProductTypeFieldPlacement::withoutWorkspaceScope()->create([
                        'workspace_id' => $lockedWorkspace->id,
                        'product_type_id' => $target->id,
                        'product_type_group_placement_id' => $targetGroup->id,
                        'field_binding_id' => $sourceField->field_binding_id,
                        'sort_order' => $sourceField->sort_order,
                        'required_for_completeness' => $sourceField->required_for_completeness,
                    ]);
                }
            }

            return $target->fresh();
        });
    }
}
