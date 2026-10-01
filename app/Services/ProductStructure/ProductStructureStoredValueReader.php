<?php

namespace App\Services\ProductStructure;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\Category;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductVariant;

final class ProductStructureStoredValueReader
{
    private const RULES = [
        'internal_product_id' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.id', AttributeDataType::Number, 'id'],
        'name' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.name', AttributeDataType::Text, 'name'],
        'brand' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.brand', AttributeDataType::Text, 'brand'],
        'category' => [FieldObjectType::Product, AttributeStorageType::Relation, 'products.category_id', AttributeDataType::Text, 'category_id'],
        'description' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.description', AttributeDataType::LongText, 'description'],
        'status' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.is_active', AttributeDataType::Boolean, 'is_active'],
        'url' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.url', AttributeDataType::Url, 'url'],
        'sku' => [FieldObjectType::ProductVariant, AttributeStorageType::Column, 'product_variants.sku', AttributeDataType::Text, 'sku'],
        'gtin' => [FieldObjectType::ProductVariant, AttributeStorageType::Column, 'product_variants.barcode_ean', AttributeDataType::Text, 'barcode_ean'],
        'merchant_type' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.merchant_type', AttributeDataType::Text, 'merchant_type'],
        'net_weight' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.net_weight', AttributeDataType::Decimal, 'net_weight'],
        'gross_weight' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.gross_weight', AttributeDataType::Decimal, 'gross_weight'],
        'volume_m3' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.volume_m3', AttributeDataType::Decimal, 'volume_m3'],
        'barcode_box' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.barcode_box', AttributeDataType::Text, 'barcode_box'],
        'min_order_quantity' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.min_order_quantity', AttributeDataType::Number, 'min_order_quantity'],
        'order_step' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.order_step', AttributeDataType::Number, 'order_step'],
        'package_quantity' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.package_quantity', AttributeDataType::Number, 'package_quantity'],
        'package_type' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.package_type', AttributeDataType::Text, 'package_type'],
        'units_per_box' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.units_per_box', AttributeDataType::Number, 'units_per_box'],
        'boxes_per_pallet' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.boxes_per_pallet', AttributeDataType::Number, 'boxes_per_pallet'],
        'lead_time_days' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.lead_time_days', AttributeDataType::Number, 'lead_time_days'],
        'depth_mm' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.depth_mm', AttributeDataType::Number, 'depth_mm'],
        'width_mm' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.width_mm', AttributeDataType::Number, 'width_mm'],
        'height_mm' => [FieldObjectType::Product, AttributeStorageType::Column, 'products.height_mm', AttributeDataType::Number, 'height_mm'],
    ];

    public function isComplete(Product|ProductVariant $target, FieldBinding $binding, FieldDefinition $definition): bool
    {
        $rule = self::RULES[$definition->code] ?? null;
        if ($rule === null || ! $this->matches($binding, $definition, $rule)) {
            return false;
        }

        [$objectType, $storageType, , $dataType, $attribute] = $rule;
        if (($target instanceof Product ? FieldObjectType::Product : FieldObjectType::ProductVariant) !== $objectType) {
            return false;
        }

        $value = $target->getAttribute($attribute);
        if ($storageType === AttributeStorageType::Relation) {
            return $definition->code === 'category'
                && $value !== null
                && Category::withoutWorkspaceScope()
                    ->whereKey($value)
                    ->where('workspace_id', $target->workspace_id)
                    ->exists();
        }

        return match ($dataType) {
            AttributeDataType::Text,
            AttributeDataType::LongText => is_string($value) && trim($value) !== '',
            AttributeDataType::Url => is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_URL) !== false,
            AttributeDataType::Number,
            AttributeDataType::Decimal => $value !== null && is_numeric($value),
            AttributeDataType::Boolean => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
            default => false,
        };
    }

    /** @param array{0: FieldObjectType, 1: AttributeStorageType, 2: string, 3: AttributeDataType, 4: string} $rule */
    private function matches(FieldBinding $binding, FieldDefinition $definition, array $rule): bool
    {
        [$objectType, $storageType, $storagePath, $dataType] = $rule;

        return $definition->workspace_id === null
            && $definition->scope === AttributeScope::System
            && $definition->status === AttributeStatus::Active
            && $definition->data_type === $dataType
            && ! $definition->is_localizable
            && ! $definition->is_multi_value
            && ($definition->validation_rules === null || $definition->validation_rules === [])
            && $binding->workspace_id === null
            && $binding->status === AttributeStatus::Active
            && $binding->object_type === $objectType
            && $binding->storage_type === $storageType
            && $binding->storage_path === $storagePath;
    }
}
