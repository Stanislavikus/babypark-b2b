<?php

namespace App\Services\Catalog;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Exceptions\Catalog\ColumnFieldClearRejectedException;
use App\Exceptions\Catalog\ColumnFieldNotAllowlistedException;
use App\Exceptions\Catalog\InvalidColumnFieldValueException;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Fields\Exceptions\FieldBindingArchivedException;
use App\Services\Fields\Exceptions\FieldBindingNotFoundException;
use App\Services\Fields\Exceptions\FieldBindingObjectTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingStorageTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingWorkspaceMismatchException;
use App\Services\Fields\Exceptions\FieldDefinitionArchivedException;
use App\Services\Fields\Exceptions\FieldDefinitionNotFoundException;
use App\Services\Fields\Exceptions\FieldDefinitionWorkspaceMismatchException;
use App\Services\Fields\Exceptions\TargetNotFoundException;
use App\Services\Fields\Exceptions\TargetWorkspaceMismatchException;
use App\Services\Fields\Exceptions\UnsupportedFieldObjectTypeException;
use Illuminate\Support\Facades\DB;

final class GovernedProductVariantColumnMutationService
{
    private const DEADLOCK_RETRY_ATTEMPTS = 5;

    private const NAME_MAX_LENGTH = 255;

    private const DESCRIPTION_MAX_BYTES = 65535;

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

    public function set(
        string $workspaceId,
        FieldObjectType $targetType,
        int|string $targetId,
        string $fieldBindingId,
        mixed $value,
    ): ColumnMutationResult {
        return DB::transaction(function () use ($workspaceId, $targetType, $targetId, $fieldBindingId, $value): ColumnMutationResult {
            $binding = $this->lockBindingForMutation($fieldBindingId, $workspaceId, $targetType);
            $definition = $this->lockDefinitionForMutation($binding);
            $rule = $this->resolveAllowlistedRule($binding, $definition);
            $target = $this->lockTargetForMutation($targetType, $targetId, $workspaceId);

            $nextValue = $this->normalizeSetPayload($definition->code, $value);
            $column = $rule['column'];
            $currentValue = $target->getAttribute($column);

            if ($currentValue === $nextValue) {
                return new ColumnMutationResult(ColumnMutationResult::NoOp, (string) $binding->id);
            }

            $target->{$column} = $nextValue;
            $target->save();

            return new ColumnMutationResult(ColumnMutationResult::Updated, (string) $binding->id);
        }, self::DEADLOCK_RETRY_ATTEMPTS);
    }

    public function clear(
        string $workspaceId,
        FieldObjectType $targetType,
        int|string $targetId,
        string $fieldBindingId,
    ): ColumnMutationResult {
        return DB::transaction(function () use ($workspaceId, $targetType, $targetId, $fieldBindingId): ColumnMutationResult {
            $binding = $this->lockBindingForMutation($fieldBindingId, $workspaceId, $targetType);
            $definition = $this->lockDefinitionForMutation($binding);
            $rule = $this->resolveAllowlistedRule($binding, $definition);
            $target = $this->lockTargetForMutation($targetType, $targetId, $workspaceId);

            if (! $rule['clear_allowed']) {
                throw ColumnFieldClearRejectedException::forField($definition->code);
            }

            $column = $rule['column'];
            $currentValue = $target->getAttribute($column);

            if ($currentValue === null) {
                return new ColumnMutationResult(ColumnMutationResult::NoOp, (string) $binding->id);
            }

            $target->{$column} = null;
            $target->save();

            return new ColumnMutationResult(ColumnMutationResult::Updated, (string) $binding->id);
        }, self::DEADLOCK_RETRY_ATTEMPTS);
    }

    private function lockBindingForMutation(
        string $fieldBindingId,
        string $workspaceId,
        FieldObjectType $targetType,
    ): FieldBinding {
        $this->assertSupportedTargetType($targetType);

        $binding = FieldBinding::withoutWorkspaceScope()
            ->whereKey($fieldBindingId)
            ->sharedLock()
            ->first();

        if ($binding === null) {
            throw FieldBindingNotFoundException::forId($fieldBindingId);
        }

        if ($binding->workspace_id !== null && $binding->workspace_id !== $workspaceId) {
            throw FieldBindingWorkspaceMismatchException::forId(
                $fieldBindingId,
                $workspaceId,
                $binding->workspace_id,
            );
        }

        if ($binding->status !== AttributeStatus::Active) {
            throw FieldBindingArchivedException::forId($fieldBindingId, $binding->status);
        }

        if ($binding->object_type !== $targetType) {
            throw FieldBindingObjectTypeMismatchException::forId(
                $fieldBindingId,
                $targetType,
                $binding->object_type,
            );
        }

        if ($binding->storage_type !== AttributeStorageType::Column) {
            throw FieldBindingStorageTypeMismatchException::forId(
                $fieldBindingId,
                $binding->storage_type,
            );
        }

        return $binding;
    }

    private function lockDefinitionForMutation(FieldBinding $binding): FieldDefinition
    {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->sharedLock()
            ->first();

        if ($definition === null) {
            throw FieldDefinitionNotFoundException::forId((string) $binding->field_definition_id);
        }

        if (($definition->workspace_id ?? null) !== ($binding->workspace_id ?? null)) {
            throw FieldDefinitionWorkspaceMismatchException::forId(
                $definition->id,
                (string) ($binding->workspace_id ?? '<global>'),
                $definition->workspace_id,
            );
        }

        if ($definition->status !== AttributeStatus::Active) {
            throw FieldDefinitionArchivedException::forId($definition->id, $definition->status);
        }

        return $definition;
    }

    private function lockTargetForMutation(
        FieldObjectType $targetType,
        int|string $targetId,
        string $workspaceId,
    ): Product|ProductVariant {
        if ($targetType === FieldObjectType::Product) {
            $product = Product::withoutWorkspaceScope()
                ->whereKey($targetId)
                ->lockForUpdate()
                ->first();

            if ($product === null) {
                throw TargetNotFoundException::product($targetId);
            }

            if ($product->workspace_id !== $workspaceId) {
                throw TargetWorkspaceMismatchException::product(
                    (int) $targetId,
                    $workspaceId,
                    (string) $product->workspace_id,
                );
            }

            return $product;
        }

        if ($targetType === FieldObjectType::ProductVariant) {
            $variant = ProductVariant::withoutWorkspaceScope()
                ->whereKey($targetId)
                ->lockForUpdate()
                ->first();

            if ($variant === null) {
                throw TargetNotFoundException::variant($targetId);
            }

            if ($variant->workspace_id !== $workspaceId) {
                throw TargetWorkspaceMismatchException::variant(
                    (int) $targetId,
                    $workspaceId,
                    (string) $variant->workspace_id,
                );
            }

            return $variant;
        }

        throw UnsupportedFieldObjectTypeException::forType($targetType);
    }

    /**
     * @return array{column: string, clear_allowed: bool}
     */
    private function resolveAllowlistedRule(FieldBinding $binding, FieldDefinition $definition): array
    {
        $rule = self::ALLOWLIST[$definition->code] ?? null;

        if ($rule === null) {
            throw ColumnFieldNotAllowlistedException::forBinding(
                (string) $binding->id,
                $definition->code,
                $binding->storage_path,
            );
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
            throw ColumnFieldNotAllowlistedException::forBinding(
                (string) $binding->id,
                $definition->code,
                $binding->storage_path,
            );
        }

        return [
            'column' => $rule['column'],
            'clear_allowed' => $rule['clear_allowed'],
        ];
    }

    private function hasSupportedValidationRules(FieldDefinition $definition): bool
    {
        return $definition->validation_rules === null || $definition->validation_rules === [];
    }

    private function normalizeSetPayload(string $fieldCode, mixed $value): string
    {
        if ($value === null) {
            throw InvalidColumnFieldValueException::nullSetPayload($fieldCode);
        }

        if (! is_string($value)) {
            throw InvalidColumnFieldValueException::nonStringPayload($fieldCode);
        }

        return match ($fieldCode) {
            'name' => $this->normalizeName($value),
            'description' => $this->normalizeDescription($value),
            default => throw ColumnFieldNotAllowlistedException::forBinding('<unknown>', $fieldCode, null),
        };
    }

    private function normalizeName(string $value): string
    {
        if ($value === '') {
            throw InvalidColumnFieldValueException::emptyName();
        }

        if (trim($value) === '') {
            throw InvalidColumnFieldValueException::whitespaceOnlyName();
        }

        if (mb_strlen($value) > self::NAME_MAX_LENGTH) {
            throw InvalidColumnFieldValueException::nameTooLong(self::NAME_MAX_LENGTH);
        }

        return $value;
    }

    private function normalizeDescription(string $value): string
    {
        if (strlen($value) > self::DESCRIPTION_MAX_BYTES) {
            throw InvalidColumnFieldValueException::descriptionTooLong(self::DESCRIPTION_MAX_BYTES);
        }

        return $value;
    }

    private function assertSupportedTargetType(FieldObjectType $targetType): void
    {
        if (in_array($targetType, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            return;
        }

        throw UnsupportedFieldObjectTypeException::forType($targetType);
    }
}
