<?php

namespace App\Services\Fields;

use App\Enums\AttributeDataType;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Services\Fields\Exceptions\FieldBindingArchivedException;
use App\Services\Fields\Exceptions\FieldBindingNotFoundException;
use App\Services\Fields\Exceptions\FieldBindingObjectTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingStorageTypeMismatchException;
use App\Services\Fields\Exceptions\FieldBindingWorkspaceMismatchException;
use App\Services\Fields\Exceptions\FieldDefinitionArchivedException;
use App\Services\Fields\Exceptions\FieldDefinitionNotFoundException;
use App\Services\Fields\Exceptions\FieldDefinitionWorkspaceMismatchException;
use App\Services\Fields\Exceptions\InvalidFieldValuePayloadException;
use App\Services\Fields\Exceptions\InvalidSelectOptionException;
use App\Services\Fields\Exceptions\LocalizationContractViolationException;
use App\Services\Fields\Exceptions\MultiValueNotSupportedException;
use App\Services\Fields\Exceptions\TargetNotFoundException;
use App\Services\Fields\Exceptions\TargetWorkspaceMismatchException;
use App\Services\Fields\Exceptions\UnsupportedFieldDataTypeException;
use App\Services\Fields\Exceptions\UnsupportedFieldObjectTypeException;
use App\Services\Fields\Exceptions\UnsupportedFieldValidationRulesException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Governed Product / ProductVariant dynamic field-value writer.
 *
 * Scope: the minimum-safe platform-core runtime slice that establishes
 * the reusable persistence, workspace, localization, clear, option-validation
 * and concurrency boundary for ordinary dynamic FieldBinding values.
 *
 * Supported target / datatype matrix:
 *   - Product        + Text / LongText   (non-localizable / localizable)
 *   - Product        + Number / Decimal  (single-value, non-localizable)
 *   - Product        + Boolean / Date    (single-value, non-localizable)
 *   - Product        + Select            (single-value, non-localizable)
 *   - Product        + MultiSelect       (multi-value, non-localizable)
 *   - Product        + Url               (single-value, non-localizable)
 *   - ProductVariant + Text / LongText   (non-localizable / localizable)
 *   - ProductVariant + Number / Decimal  (single-value, non-localizable)
 *   - ProductVariant + Boolean / Date    (single-value, non-localizable)
 *   - ProductVariant + Select            (single-value, non-localizable)
 *   - ProductVariant + MultiSelect       (multi-value, non-localizable)
 *   - ProductVariant + Url               (single-value, non-localizable)
 *
 * Fail-closed for: Money, Image, Computed, unsupported validation rules,
 *                  and inconsistent localization/cardinality metadata.
 *
 * No schema changes; no dependencies on ConnectorAccount /
 * SyncConfiguration / ExternalRecordLink / Adobe / FieldMapping.
 *
 * Public API: set() and clear(). Each is a single-locale mutation;
 * whole-map localization overwrites are explicitly NOT exposed in
 * GAP-028 callers (Magento Receive, CSV/Smart Import, Google Sheets,
 * ERP/1C, product-card editing) MUST drive per-locale Set/Clear.
 *
 * Concurrency: DB::transaction + lockForUpdate on the existing slot
 * row (when present). On absent-slot create, bounded retry on the
 * unique-constraint violation: re-read under lockForUpdate and
 * reapply the operation. This guarantees that the final row state
 * is deterministic, never duplicated, and never contains a
 * payload corruption from a flat-merge race.
 */
final class GovernedDynamicFieldValueWriter
{
    private const DEADLOCK_RETRY_ATTEMPTS = 5;

    /**
     * Max concurrent-retry attempts for absent-slot create + unique-violation.
     * Bounded; on exhaustion the unique violation is re-thrown so the caller
     * is not silently masked into a wrong-state.
     */
    private const ABSENT_SLOT_CREATE_RETRY_LIMIT = 5;

    private const PRODUCT_SLOT_UNIQUE_INDEX = 'product_field_values_ws_product_binding_unique';

    private const VARIANT_SLOT_UNIQUE_INDEX = 'variant_field_values_ws_variant_binding_unique';

    public function __construct(
        private readonly FieldDefinitionSelectOptionValidator $selectOptionValidator,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Persist a single value for a dynamic FieldBinding slot.
     *
     * Canonical storage:
     *   - non-localizable text/long_text/select/date/url  → value_text only;
     *                                                        value_num/value_jsonb nulled.
     *   - non-localizable number/decimal/boolean          → value_num only;
     *                                                        value_text/value_jsonb nulled.
     *   - non-localizable multi_select                    → value_jsonb only;
     *                                                        value_text/value_num nulled.
     *   - localizable text/long_text             → value_jsonb[locale] only;
     *                                              value_text/value_num nulled.
     *
     * Semantics:
     *   - set(null) is REJECTED — use clear().
     *   - set('') for text/long_text is a legitimate explicit value
     *     (it is not silently coerced to clear()).
     *   - same-value set is NoOp (no DB mutation).
     *
     * @throws TargetNotFoundException
     * @throws TargetWorkspaceMismatchException
     * @throws FieldBindingNotFoundException
     * @throws FieldBindingWorkspaceMismatchException
     * @throws FieldBindingArchivedException
     * @throws FieldBindingObjectTypeMismatchException
     * @throws FieldBindingStorageTypeMismatchException
     * @throws FieldDefinitionNotFoundException
     * @throws FieldDefinitionWorkspaceMismatchException
     * @throws FieldDefinitionArchivedException
     * @throws UnsupportedFieldDataTypeException
     * @throws MultiValueNotSupportedException
     * @throws LocalizationContractViolationException
     * @throws InvalidSelectOptionException
     * @throws InvalidFieldValuePayloadException
     */
    public function set(
        string $workspaceId,
        FieldObjectType $targetType,
        int|string $targetId,
        string $fieldBindingId,
        mixed $value,
        ?string $locale = null,
    ): FieldValueWriteResult {
        if ($value === null) {
            throw InvalidFieldValuePayloadException::nullPayload();
        }

        $context = $this->resolveContext(
            workspaceId: $workspaceId,
            targetType: $targetType,
            targetId: $targetId,
            fieldBindingId: $fieldBindingId,
            locale: $locale,
        );

        return $this->mutateWithRetry(
            context: $context,
            operation: 'set',
            value: $value,
            locale: $locale,
        );
    }

    /**
     * Explicitly remove a value from a dynamic FieldBinding slot.
     *
     * Semantics:
     *   - non-localizable binding + non-empty existing value → delete the row.
     *   - non-localizable binding + absent/already-empty       → NoOp.
     *   - localizable binding + locale present                 → remove only
     *     that locale from value_jsonb; preserve all other locales.
     *   - localizable binding + locale removed last locale     → delete the row.
     *   - localizable binding + locale already absent          → NoOp.
     *   - localizable Clear MUST receive a locale (per the public
     *     single-locale API contract).
     */
    public function clear(
        string $workspaceId,
        FieldObjectType $targetType,
        int|string $targetId,
        string $fieldBindingId,
        ?string $locale = null,
    ): FieldValueWriteResult {
        $context = $this->resolveContext(
            workspaceId: $workspaceId,
            targetType: $targetType,
            targetId: $targetId,
            fieldBindingId: $fieldBindingId,
            locale: $locale,
        );

        return $this->mutateWithRetry(
            context: $context,
            operation: 'clear',
            value: null,
            locale: $locale,
        );
    }

    // ------------------------------------------------------------------
    // Context resolution (workspace, target, binding, definition)
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   workspace_id: string,
     *   target: Product|ProductVariant,
     *   target_type: FieldObjectType,
     *   field_binding_id: string,
     *   binding: FieldBinding,
     *   definition: FieldDefinition
     * }
     */
    private function resolveContext(
        string $workspaceId,
        FieldObjectType $targetType,
        int|string $targetId,
        string $fieldBindingId,
        ?string $locale,
    ): array {
        $binding = FieldBinding::withoutWorkspaceScope()->find($fieldBindingId);

        if ($binding === null) {
            throw FieldBindingNotFoundException::forId($fieldBindingId);
        }

        // Binding ownership rule (strict per GAP-028A override A):
        //   binding.workspace_id is global (NULL) OR binding.workspace_id === explicit workspace
        //   target.workspace_id === explicit workspace
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

        if ($binding->storage_type !== AttributeStorageType::Dynamic) {
            throw FieldBindingStorageTypeMismatchException::forId(
                $fieldBindingId,
                $binding->storage_type,
            );
        }

        $definition = FieldDefinition::withoutWorkspaceScope()->find($binding->field_definition_id);

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

        $this->assertDataTypeSupported($definition);
        $this->assertValidationRulesSupported($definition);
        $this->assertLocalizationContract($definition, $locale);
        $this->assertTargetTypeSupported($targetType);

        $target = $this->resolveTarget($targetType, $targetId, $workspaceId);

        return [
            'workspace_id' => $workspaceId,
            'target' => $target,
            'target_type' => $targetType,
            'field_binding_id' => $fieldBindingId,
            'binding' => $binding,
            'definition' => $definition,
        ];
    }

    private function resolveTarget(
        FieldObjectType $targetType,
        int|string $targetId,
        string $workspaceId,
    ): Product|ProductVariant {
        if ($targetType === FieldObjectType::Product) {
            $product = Product::withoutWorkspaceScope()->find($targetId);

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
            $variant = ProductVariant::withoutWorkspaceScope()->find($targetId);

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

    private function assertDataTypeSupported(FieldDefinition $definition): void
    {
        $type = $definition->data_type;

        match ($type) {
            AttributeDataType::Text,
            AttributeDataType::LongText => $this->assertTextualMetadataContract($definition),
            AttributeDataType::Number,
            AttributeDataType::Decimal,
            AttributeDataType::Boolean,
            AttributeDataType::Date,
            AttributeDataType::Url,
            AttributeDataType::Select => $this->assertSingleValueNonLocalizableContract($definition),
            AttributeDataType::MultiSelect => $this->assertMultiSelectMetadataContract($definition),
            default => throw UnsupportedFieldDataTypeException::forType($type, $definition->id),
        };
    }

    private function assertTextualMetadataContract(FieldDefinition $definition): void
    {
        if ($definition->is_multi_value) {
            throw MultiValueNotSupportedException::singleValueRequired(
                $definition->id,
                $definition->data_type->value,
            );
        }
    }

    private function assertSingleValueNonLocalizableContract(FieldDefinition $definition): void
    {
        if ($definition->is_multi_value) {
            throw MultiValueNotSupportedException::singleValueRequired(
                $definition->id,
                $definition->data_type->value,
            );
        }

        if ($definition->is_localizable) {
            if ($definition->data_type === AttributeDataType::Select) {
                throw LocalizationContractViolationException::localizableSelectNotSupported($definition->id);
            }

            throw LocalizationContractViolationException::nonTextLocalizableNotSupported(
                $definition->id,
                $definition->data_type,
            );
        }
    }

    private function assertMultiSelectMetadataContract(FieldDefinition $definition): void
    {
        if (! $definition->is_multi_value) {
            throw MultiValueNotSupportedException::multiValueRequired(
                $definition->id,
                $definition->data_type->value,
            );
        }

        if ($definition->is_localizable) {
            throw LocalizationContractViolationException::nonTextLocalizableNotSupported(
                $definition->id,
                $definition->data_type,
            );
        }
    }

    private function assertValidationRulesSupported(FieldDefinition $definition): void
    {
        $rules = $definition->validation_rules;

        if ($rules === null) {
            return;
        }

        if (! is_array($rules)) {
            throw UnsupportedFieldValidationRulesException::forDefinition(
                $definition->data_type,
                $definition->id,
            );
        }

        if (in_array($definition->data_type, [
            AttributeDataType::Text,
            AttributeDataType::LongText,
            AttributeDataType::Number,
            AttributeDataType::Decimal,
            AttributeDataType::Boolean,
            AttributeDataType::Date,
            AttributeDataType::Url,
        ], true)) {
            if ($rules !== []) {
                throw UnsupportedFieldValidationRulesException::forDefinition(
                    $definition->data_type,
                    $definition->id,
                );
            }

            return;
        }

        if (! in_array($definition->data_type, [AttributeDataType::Select, AttributeDataType::MultiSelect], true)) {
            return;
        }

        foreach ($rules as $ruleName => $ruleValue) {
            if ($ruleName === 'options') {
                continue;
            }

            if ($this->isMeaningfullyNonEmptyRuleValue($ruleValue)) {
                throw UnsupportedFieldValidationRulesException::forDefinition(
                    $definition->data_type,
                    $definition->id,
                );
            }
        }
    }

    private function isMeaningfullyNonEmptyRuleValue(mixed $ruleValue): bool
    {
        if ($ruleValue === null || $ruleValue === '' || $ruleValue === false) {
            return false;
        }

        if (is_array($ruleValue)) {
            return $ruleValue !== [];
        }

        return true;
    }

    private function assertTargetTypeSupported(FieldObjectType $targetType): void
    {
        if (in_array($targetType, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            return;
        }

        throw UnsupportedFieldObjectTypeException::forType($targetType);
    }

    private function assertLocalizationContract(FieldDefinition $definition, ?string $locale): void
    {
        if ($definition->is_localizable) {
            if ($locale === null || $locale === '') {
                throw LocalizationContractViolationException::localeRequiredForLocalizable($definition->id);
            }
        } else {
            if ($locale !== null && $locale !== '') {
                throw LocalizationContractViolationException::localeForbiddenForNonLocalizable($definition->id);
            }
        }
    }

    // ------------------------------------------------------------------
    // Mutation with bounded retry (absent-slot create race)
    // ------------------------------------------------------------------

    /**
     * @param  array{
     *   workspace_id: string,
     *   target: Product|ProductVariant,
     *   target_type: FieldObjectType,
     *   field_binding_id: string,
     *   binding: FieldBinding,
     *   definition: FieldDefinition
     * }  $context
     */
    private function mutateWithRetry(
        array $context,
        string $operation,
        mixed $value,
        ?string $locale,
    ): FieldValueWriteResult {
        $modelClass = $this->valueModelFor($context['target_type']);
        $entityColumn = $this->entityColumnFor($context['target_type']);
        $entityId = (int) $context['target']->getKey();
        $workspaceId = $context['workspace_id'];
        $fieldBindingId = $context['field_binding_id'];
        $lastExpectedUniqueViolation = null;

        for ($attempt = 1; $attempt <= self::ABSENT_SLOT_CREATE_RETRY_LIMIT; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $modelClass,
                    $entityColumn,
                    $entityId,
                    $workspaceId,
                    $fieldBindingId,
                    $operation,
                    $value,
                    $locale,
                    $context,
                ): FieldValueWriteResult {
                    $binding = $this->lockBindingForMutation(
                        fieldBindingId: $fieldBindingId,
                        workspaceId: $workspaceId,
                        targetType: $context['target_type'],
                    );
                    $definition = $this->lockDefinitionForMutation(
                        binding: $binding,
                        locale: $locale,
                    );

                    $slot = $modelClass::withoutWorkspaceScope()
                        ->where('workspace_id', $workspaceId)
                        ->where($entityColumn, $entityId)
                        ->where('field_binding_id', $binding->id)
                        ->lockForUpdate()
                        ->first();

                    if ($operation === 'set') {
                        return $this->applySet(
                            modelClass: $modelClass,
                            entityColumn: $entityColumn,
                            entityId: $entityId,
                            workspaceId: $workspaceId,
                            binding: $binding,
                            definition: $definition,
                            slot: $slot,
                            value: $value,
                            locale: $locale,
                        );
                    }

                    return $this->applyClear(
                        modelClass: $modelClass,
                        entityColumn: $entityColumn,
                        entityId: $entityId,
                        workspaceId: $workspaceId,
                        binding: $binding,
                        definition: $definition,
                        slot: $slot,
                        locale: $locale,
                    );
                }, self::DEADLOCK_RETRY_ATTEMPTS);
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isExpectedSlotUniqueViolation($exception, $context['target_type'])) {
                    throw $exception;
                }

                $lastExpectedUniqueViolation = $exception;

                if ($attempt >= self::ABSENT_SLOT_CREATE_RETRY_LIMIT) {
                    throw $lastExpectedUniqueViolation;
                }

                continue;
            }
        }

        // Unreachable; the loop either returns or throws.
        throw new \LogicException('mutateWithRetry exited without returning or throwing.');
    }

    private function isExpectedSlotUniqueViolation(
        UniqueConstraintViolationException $exception,
        FieldObjectType $targetType,
    ): bool {
        $expectedIndex = match ($targetType) {
            FieldObjectType::Product => self::PRODUCT_SLOT_UNIQUE_INDEX,
            FieldObjectType::ProductVariant => self::VARIANT_SLOT_UNIQUE_INDEX,
            default => null,
        };

        if ($expectedIndex === null) {
            return false;
        }

        return str_contains((string) $exception->getMessage(), $expectedIndex);
    }

    private function lockBindingForMutation(
        string $fieldBindingId,
        string $workspaceId,
        FieldObjectType $targetType,
    ): FieldBinding {
        $this->assertTargetTypeSupported($targetType);

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

        if ($binding->storage_type !== AttributeStorageType::Dynamic) {
            throw FieldBindingStorageTypeMismatchException::forId(
                $fieldBindingId,
                $binding->storage_type,
            );
        }

        return $binding;
    }

    private function lockDefinitionForMutation(
        FieldBinding $binding,
        ?string $locale,
    ): FieldDefinition {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->whereKey($binding->field_definition_id)
            ->sharedLock()
            ->first();

        if ($definition === null) {
            throw FieldDefinitionNotFoundException::forId((string) $binding->field_definition_id);
        }

        if ((string) $definition->id !== (string) $binding->field_definition_id) {
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

        $this->assertDataTypeSupported($definition);
        $this->assertValidationRulesSupported($definition);
        $this->assertLocalizationContract($definition, $locale);

        return $definition;
    }

    // ------------------------------------------------------------------
    // Per-operation application (Set / Clear)
    // ------------------------------------------------------------------

    private function applySet(
        string $modelClass,
        string $entityColumn,
        int $entityId,
        string $workspaceId,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $slot,
        mixed $value,
        ?string $locale,
    ): FieldValueWriteResult {
        $isLocalizable = (bool) $definition->is_localizable;
        $type = $definition->data_type;

        if ($isLocalizable) {
            // Localizable → value_jsonb[locale] only; preserve all other locales.
            $existingMap = $this->readLocalizedMap($slot, $definition->id);

            $newMap = $existingMap;
            $newMap[$locale] = $this->normalizeStringPayloadForType($type, $value, $definition);

            // NoOp: same locale payload already present and equal.
            if (array_key_exists($locale, $existingMap)
                && $existingMap[$locale] === $newMap[$locale]
                && count($existingMap) === count($newMap)) {
                return new FieldValueWriteResult(FieldValueWriteResult::NoOp, (string) $binding->id);
            }

            $payload = [
                $entityColumn => $entityId,
                'field_binding_id' => $binding->id,
                'workspace_id' => $workspaceId,
                'value_jsonb' => $newMap,
                'value_text' => null,
                'value_num' => null,
            ];

            return $this->upsertCanonical(
                modelClass: $modelClass,
                entityColumn: $entityColumn,
                entityId: $entityId,
                binding: $binding,
                payload: $payload,
                slot: $slot,
            );
        }

        $canonicalPayload = $this->canonicalPayloadForNonLocalizableType($type, $value, $definition);

        if ($this->slotMatchesCanonicalPayload($slot, $canonicalPayload)) {
            return new FieldValueWriteResult(FieldValueWriteResult::NoOp, (string) $binding->id);
        }

        $payload = [
            $entityColumn => $entityId,
            'field_binding_id' => $binding->id,
            'workspace_id' => $workspaceId,
            'value_text' => $canonicalPayload['value_text'],
            'value_num' => $canonicalPayload['value_num'],
            'value_jsonb' => $canonicalPayload['value_jsonb'],
        ];

        return $this->upsertCanonical(
            modelClass: $modelClass,
            entityColumn: $entityColumn,
            entityId: $entityId,
            binding: $binding,
            payload: $payload,
            slot: $slot,
        );
    }

    private function applyClear(
        string $modelClass,
        string $entityColumn,
        int $entityId,
        string $workspaceId,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $slot,
        ?string $locale,
    ): FieldValueWriteResult {
        $isLocalizable = (bool) $definition->is_localizable;

        if (! $isLocalizable) {
            if ($slot === null) {
                return new FieldValueWriteResult(FieldValueWriteResult::NoOp, (string) $binding->id);
            }

            // Non-localizable Clear → delete row.
            $slot->delete();

            return new FieldValueWriteResult(FieldValueWriteResult::Deleted, (string) $binding->id);
        }

        // Localizable Clear removes only that locale; final-locale clear deletes the row.
        if ($slot === null) {
            return new FieldValueWriteResult(FieldValueWriteResult::NoOp, (string) $binding->id);
        }

        $existingMap = $this->readLocalizedMap($slot, $definition->id);

        if (! array_key_exists($locale, $existingMap)) {
            return new FieldValueWriteResult(FieldValueWriteResult::NoOp, (string) $binding->id);
        }

        unset($existingMap[$locale]);

        if ($existingMap === []) {
            $slot->delete();

            return new FieldValueWriteResult(FieldValueWriteResult::Deleted, (string) $binding->id);
        }

        $slot->value_jsonb = $existingMap;
        $slot->value_text = null;
        $slot->value_num = null;
        $slot->save();

        return new FieldValueWriteResult(FieldValueWriteResult::Updated, (string) $binding->id);
    }

    /**
     * Canonicalize a fresh or updated slot row, choosing Created vs Updated.
     * Slot may be null (absent). Lets UniqueConstraintViolationException
     * escape so the bounded retry in mutateWithRetry() can drive a fresh
     * transaction that locks the now-existing row.
     */
    private function upsertCanonical(
        string $modelClass,
        string $entityColumn,
        int $entityId,
        FieldBinding $binding,
        array $payload,
        mixed $slot,
    ): FieldValueWriteResult {
        if ($slot === null) {
            $modelClass::withoutWorkspaceScope()->create($payload);

            return new FieldValueWriteResult(FieldValueWriteResult::Created, (string) $binding->id);
        }

        $slot->fill($payload);
        $slot->save();

        return new FieldValueWriteResult(FieldValueWriteResult::Updated, (string) $binding->id);
    }

    // ------------------------------------------------------------------
    // Localized-map canonical read
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function readLocalizedMap(mixed $slot, string $fieldDefinitionId): array
    {
        if ($slot === null) {
            return [];
        }

        if ($slot->value_text !== null || $slot->value_num !== null) {
            throw LocalizationContractViolationException::corruptLocalizedStorage($fieldDefinitionId);
        }

        $raw = $slot->value_jsonb;

        if ($raw === null || $raw === '') {
            throw LocalizationContractViolationException::corruptLocalizedStorage($fieldDefinitionId);
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            throw LocalizationContractViolationException::corruptLocalizedStorage($fieldDefinitionId);
        }

        if (! is_array($decoded)) {
            throw LocalizationContractViolationException::corruptLocalizedStorage($fieldDefinitionId);
        }

        if ($decoded === []) {
            throw LocalizationContractViolationException::corruptLocalizedStorage($fieldDefinitionId);
        }

        $map = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_string($value)) {
                throw LocalizationContractViolationException::corruptLocalizedStorage($fieldDefinitionId);
            }

            $map[$key] = $value;
        }

        return $map;
    }

    // ------------------------------------------------------------------
    // Payload normalization
    // ------------------------------------------------------------------

    /**
     * @return array{value_text: ?string, value_num: ?string, value_jsonb: ?array}
     */
    private function canonicalPayloadForNonLocalizableType(
        AttributeDataType $type,
        mixed $value,
        FieldDefinition $definition,
    ): array {
        return match ($type) {
            AttributeDataType::Text,
            AttributeDataType::LongText,
            AttributeDataType::Select,
            AttributeDataType::Date,
            AttributeDataType::Url => [
                'value_text' => $this->normalizeStringPayloadForType($type, $value, $definition),
                'value_num' => null,
                'value_jsonb' => null,
            ],
            AttributeDataType::Number => [
                'value_text' => null,
                'value_num' => $this->normalizeIntegerPayload($value),
                'value_jsonb' => null,
            ],
            AttributeDataType::Decimal => [
                'value_text' => null,
                'value_num' => $this->normalizeDecimalPayload($value),
                'value_jsonb' => null,
            ],
            AttributeDataType::Boolean => [
                'value_text' => null,
                'value_num' => $this->normalizeBooleanPayload($value),
                'value_jsonb' => null,
            ],
            AttributeDataType::MultiSelect => [
                'value_text' => null,
                'value_num' => null,
                'value_jsonb' => $this->normalizeMultiSelectPayload($definition, $value),
            ],
            default => throw UnsupportedFieldDataTypeException::forType($type, $definition->id),
        };
    }

    private function normalizeStringPayloadForType(
        AttributeDataType $type,
        mixed $value,
        FieldDefinition $definition,
    ): string {
        if (! is_string($value)) {
            if ($type === AttributeDataType::Select) {
                throw InvalidFieldValuePayloadException::nonStringSelectPayload();
            }

            if ($type === AttributeDataType::Date) {
                throw InvalidFieldValuePayloadException::invalidDatePayload();
            }

            if ($type === AttributeDataType::Url) {
                throw InvalidFieldValuePayloadException::invalidUrlPayload();
            }

            throw InvalidFieldValuePayloadException::nonStringTextPayload();
        }

        if ($type === AttributeDataType::Select) {
            $this->selectOptionValidator->assertOptionAllowed($definition, $value);
        }

        if ($type === AttributeDataType::Date) {
            $this->assertValidDateString($value);
        }

        if ($type === AttributeDataType::Url) {
            $this->assertValidAbsoluteUrl($value);
        }

        return $value;
    }

    private function normalizeIntegerPayload(mixed $value): string
    {
        if (is_float($value)) {
            throw InvalidFieldValuePayloadException::floatPayloadNotAllowed('Number');
        }

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = $value;
        } else {
            throw InvalidFieldValuePayloadException::invalidIntegerPayload();
        }

        if (! preg_match('/^(?:0|-?[1-9]\d*)$/', $raw)) {
            throw InvalidFieldValuePayloadException::invalidIntegerPayload();
        }

        $negative = str_starts_with($raw, '-');
        $digits = ltrim($negative ? substr($raw, 1) : $raw, '0');
        $digits = $digits === '' ? '0' : $digits;

        if (strlen($digits) > 14) {
            throw InvalidFieldValuePayloadException::invalidIntegerPayload();
        }

        if ($digits === '0') {
            return '0.000000';
        }

        return ($negative ? '-' : '').$digits.'.000000';
    }

    private function normalizeDecimalPayload(mixed $value): string
    {
        if (is_float($value)) {
            throw InvalidFieldValuePayloadException::floatPayloadNotAllowed('Decimal');
        }

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = $value;
        } else {
            throw InvalidFieldValuePayloadException::invalidDecimalPayload();
        }

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
            throw InvalidFieldValuePayloadException::invalidDecimalPayload();
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = $negative ? substr($raw, 1) : $raw;
        [$integerPart, $fractionPart] = array_pad(explode('.', $unsigned, 2), 2, '');

        $integerPart = ltrim($integerPart, '0');
        $integerPart = $integerPart === '' ? '0' : $integerPart;

        if (strlen($integerPart) > 14) {
            throw InvalidFieldValuePayloadException::invalidDecimalPayload();
        }

        if (strlen($fractionPart) > 6) {
            $extraScale = substr($fractionPart, 6);

            if (trim($extraScale, '0') !== '') {
                throw InvalidFieldValuePayloadException::invalidDecimalPayload();
            }

            $fractionPart = substr($fractionPart, 0, 6);
        }

        $fractionPart = str_pad($fractionPart, 6, '0');
        $normalized = $integerPart.'.'.$fractionPart;

        if ($normalized === '0.000000') {
            return $normalized;
        }

        return ($negative ? '-' : '').$normalized;
    }

    private function normalizeBooleanPayload(mixed $value): string
    {
        if (! is_bool($value)) {
            throw InvalidFieldValuePayloadException::invalidBooleanPayload();
        }

        return $value ? '1.000000' : '0.000000';
    }

    /**
     * @return list<string>
     */
    private function normalizeMultiSelectPayload(FieldDefinition $definition, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw InvalidFieldValuePayloadException::invalidMultiSelectPayload();
        }

        $allowed = $this->selectOptionValidator->allowedOptionKeys($definition);

        if ($allowed === []) {
            throw InvalidSelectOptionException::optionsUndeclared($definition->id);
        }

        $seen = [];
        $codes = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw InvalidFieldValuePayloadException::invalidMultiSelectPayload();
            }

            if (isset($seen[$item])) {
                throw InvalidFieldValuePayloadException::duplicateMultiSelectCode($item);
            }

            if (! in_array($item, $allowed, true)) {
                throw InvalidSelectOptionException::forValue($item, $definition->id);
            }

            $seen[$item] = true;
            $codes[] = $item;
        }

        sort($codes, SORT_STRING);

        return $codes;
    }

    private function assertValidDateString(string $value): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw InvalidFieldValuePayloadException::invalidDatePayload();
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            throw InvalidFieldValuePayloadException::invalidDatePayload();
        }
    }

    private function assertValidAbsoluteUrl(string $value): void
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw InvalidFieldValuePayloadException::invalidUrlPayload();
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            throw InvalidFieldValuePayloadException::invalidUrlPayload();
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        $normalizedScheme = is_string($scheme) ? strtolower($scheme) : null;

        if (! is_string($normalizedScheme) || ! in_array($normalizedScheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw InvalidFieldValuePayloadException::invalidUrlPayload();
        }
    }

    /**
     * @param  array{value_text: ?string, value_num: ?string, value_jsonb: ?array}  $canonicalPayload
     */
    private function slotMatchesCanonicalPayload(mixed $slot, array $canonicalPayload): bool
    {
        if ($slot === null) {
            return false;
        }

        return $slot->value_text === $canonicalPayload['value_text']
            && (($slot->value_num === null && $canonicalPayload['value_num'] === null)
                || ((string) $slot->value_num === (string) $canonicalPayload['value_num']))
            && $slot->value_jsonb === $canonicalPayload['value_jsonb'];
    }

    // ------------------------------------------------------------------
    // Target → model/column mapping
    // ------------------------------------------------------------------

    private function valueModelFor(FieldObjectType $targetType): string
    {
        return match ($targetType) {
            FieldObjectType::Product => ProductFieldValue::class,
            FieldObjectType::ProductVariant => VariantFieldValue::class,
            default => throw UnsupportedFieldObjectTypeException::forType($targetType),
        };
    }

    private function entityColumnFor(FieldObjectType $targetType): string
    {
        return match ($targetType) {
            FieldObjectType::Product => 'product_id',
            FieldObjectType::ProductVariant => 'variant_id',
            default => throw UnsupportedFieldObjectTypeException::forType($targetType),
        };
    }
}
