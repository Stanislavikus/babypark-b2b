<?php

namespace App\Services\Catalog;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;

final class GovernedProductVariantColumnEligibility
{
    private const ALLOWLIST = [
        'name' => [
            'column' => 'name',
            'definition' => [
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'data_type' => AttributeDataType::Text,
                'status' => AttributeStatus::Active,
                'is_localizable' => false,
                'is_multi_value' => false,
            ],
            'binding' => [
                'workspace_id' => null,
                'object_type' => FieldObjectType::Product,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.name',
                'status' => AttributeStatus::Active,
            ],
            'clear_allowed' => false,
        ],
        'description' => [
            'column' => 'description',
            'definition' => [
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'data_type' => AttributeDataType::LongText,
                'status' => AttributeStatus::Active,
                'is_localizable' => false,
                'is_multi_value' => false,
            ],
            'binding' => [
                'workspace_id' => null,
                'object_type' => FieldObjectType::Product,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.description',
                'status' => AttributeStatus::Active,
            ],
            'clear_allowed' => true,
        ],
    ];

    /**
     * @return array{column: string, clear_allowed: bool}|null
     */
    public function matchingRule(FieldBinding $binding, FieldDefinition $definition): ?array
    {
        $rule = self::ALLOWLIST[$definition->code] ?? null;

        if ($rule === null) {
            return null;
        }

        $definitionRule = $rule['definition'];
        $bindingRule = $rule['binding'];

        $definitionMatches = $definition->workspace_id === $definitionRule['workspace_id']
            && $definition->scope === $definitionRule['scope']
            && $definition->data_type === $definitionRule['data_type']
            && $definition->status === $definitionRule['status']
            && $definition->is_localizable === $definitionRule['is_localizable']
            && $definition->is_multi_value === $definitionRule['is_multi_value']
            && $this->hasSupportedValidationRules($definition);

        $bindingMatches = $binding->workspace_id === $bindingRule['workspace_id']
            && $binding->object_type === $bindingRule['object_type']
            && $binding->storage_type === $bindingRule['storage_type']
            && $binding->storage_path === $bindingRule['storage_path']
            && $binding->status === $bindingRule['status'];

        if (! $definitionMatches || ! $bindingMatches) {
            return null;
        }

        return [
            'column' => $rule['column'],
            'clear_allowed' => $rule['clear_allowed'],
        ];
    }

    public function isCanonicalField(FieldBinding $binding, FieldDefinition $definition, string $fieldCode): bool
    {
        if ($definition->code !== $fieldCode) {
            return false;
        }

        return $this->matchingRule($binding, $definition) !== null;
    }

    private function hasSupportedValidationRules(FieldDefinition $definition): bool
    {
        return $definition->validation_rules === null || $definition->validation_rules === [];
    }
}
