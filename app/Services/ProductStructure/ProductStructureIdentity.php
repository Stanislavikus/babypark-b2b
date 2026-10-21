<?php

namespace App\Services\ProductStructure;

use Ramsey\Uuid\Uuid;

final class ProductStructureIdentity
{
    public static function basicProductTypeId(string $workspaceId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "product-structure:{$workspaceId}:product-type:basic-product")->toString();
    }

    public static function attributeGroupId(string $workspaceId, string $code): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "product-structure:{$workspaceId}:attribute-group:{$code}")->toString();
    }

    public static function groupPlacementId(string $workspaceId, string $productTypeId, string $groupId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "product-structure:{$workspaceId}:product-type:{$productTypeId}:group:{$groupId}")->toString();
    }

    public static function fieldPlacementId(string $workspaceId, string $productTypeId, string $bindingId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "product-structure:{$workspaceId}:product-type:{$productTypeId}:binding:{$bindingId}")->toString();
    }
}
