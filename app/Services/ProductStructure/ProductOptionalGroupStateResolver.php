<?php

namespace App\Services\ProductStructure;

use App\Models\Product;
use App\Models\ProductActiveOptionalGroup;
use App\Models\ProductTypeGroupPlacement;

final class ProductOptionalGroupStateResolver
{
    public function isActive(Product $product, ProductTypeGroupPlacement $placement): bool
    {
        if (! $placement->is_optional) {
            return true;
        }

        $override = ProductActiveOptionalGroup::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->where('product_type_group_placement_id', $placement->id)
            ->value('is_active');

        return $override === null ? (bool) $placement->default_active : (bool) $override;
    }
}
