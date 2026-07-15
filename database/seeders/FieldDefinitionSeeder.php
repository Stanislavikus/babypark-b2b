<?php

namespace Database\Seeders;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Illuminate\Database\Seeder;
use RuntimeException;

class FieldDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = array_merge(
            $this->systemAttributes(),
            $this->platformLibraryAttributes(),
        );

        foreach ($definitions as $seed) {
            $definition = $this->upsertDefinition($seed);

            foreach ($this->objectTypesForValueLevel($seed['value_level']) as $objectType) {
                $this->upsertBinding($definition, $objectType, $seed);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $seed
     */
    private function upsertDefinition(array $seed): FieldDefinition
    {
        $workspaceId = $seed['workspace_id'] ?? null;
        $scope = $seed['scope'];
        $code = $seed['code'];

        $expected = [
            'workspace_id' => $workspaceId,
            'code' => $code,
            'data_type' => $seed['data_type'],
            'scope' => $scope,
            'localized_labels' => $seed['localized_labels'],
            'description' => null,
            'validation_rules' => $seed['validation_rules'],
            'is_localizable' => $seed['is_localizable'],
            'is_multi_value' => $seed['is_multi_value'],
            'status' => $seed['status'],
        ];

        $existing = FieldDefinition::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('scope', $scope)
            ->where('code', $code)
            ->first();

        if ($existing !== null) {
            $conflicts = $this->definitionConflicts($existing, $expected);

            if ($conflicts !== []) {
                throw new RuntimeException(
                    "Field definition conflict for code '{$code}':\n- ".implode("\n- ", $conflicts)
                );
            }

            return $existing;
        }

        return FieldDefinition::withoutWorkspaceScope()->create($expected);
    }

    /**
     * @param  array<string, mixed>  $seed
     */
    private function upsertBinding(FieldDefinition $definition, FieldObjectType $objectType, array $seed): FieldBinding
    {
        $workspaceId = $seed['workspace_id'] ?? null;

        $expected = [
            'workspace_id' => $workspaceId,
            'field_definition_id' => $definition->id,
            'object_type' => $objectType,
            'storage_type' => $seed['storage_type'],
            'storage_path' => $seed['storage_path'],
            'field_group' => $seed['attribute_group'],
            'is_required' => $seed['is_required'],
            'is_filterable' => $seed['is_filterable'],
            'is_sortable' => $seed['is_sortable'],
            'visibility_settings' => $seed['visibility_settings'],
            'sort_order' => $seed['sort_order'],
            'status' => $seed['status'],
        ];

        $existing = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', $objectType)
            ->first();

        if ($existing !== null) {
            $conflicts = $this->bindingConflicts($existing, $expected);

            if ($conflicts !== []) {
                throw new RuntimeException(
                    "Field binding conflict for '{$definition->code}' ({$objectType->value}):\n- ".implode("\n- ", $conflicts)
                );
            }

            return $existing;
        }

        return FieldBinding::withoutWorkspaceScope()->create($expected);
    }

    /**
     * @return list<FieldObjectType>
     */
    /**
     * @return list<FieldObjectType>
     */
    private function objectTypesForValueLevel(string $valueLevel): array
    {
        return match ($valueLevel) {
            'product' => [FieldObjectType::Product],
            'variant' => [FieldObjectType::ProductVariant],
            'both' => [FieldObjectType::Product, FieldObjectType::ProductVariant],
            default => throw new RuntimeException("Unknown value_level '{$valueLevel}'"),
        };
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function definitionConflicts(FieldDefinition $existing, array $expected): array
    {
        $conflicts = [];

        foreach (['data_type', 'scope', 'is_localizable', 'is_multi_value', 'status'] as $field) {
            $actual = $existing->{$field};
            $exp = $expected[$field];
            $expValue = $exp instanceof \BackedEnum ? $exp->value : $exp;
            $actualValue = $actual instanceof \BackedEnum ? $actual->value : $actual;

            if ((string) $actualValue !== (string) $expValue) {
                $conflicts[] = "{$field} expected ".json_encode($expValue).', got '.json_encode($actualValue);
            }
        }

        if (! $this->valuesSemanticallyEqual($existing->localized_labels, $expected['localized_labels'])) {
            $conflicts[] = 'localized_labels mismatch';
        }

        if (! $this->valuesSemanticallyEqual($existing->description, $expected['description'])) {
            $conflicts[] = 'description mismatch';
        }

        if (! $this->valuesSemanticallyEqual($existing->validation_rules, $expected['validation_rules'])) {
            $conflicts[] = 'validation_rules mismatch';
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function bindingConflicts(FieldBinding $existing, array $expected): array
    {
        $conflicts = [];

        foreach ([
            'workspace_id',
            'storage_type',
            'storage_path',
            'field_group',
            'is_required',
            'is_filterable',
            'is_sortable',
            'sort_order',
            'status',
        ] as $field) {
            $actual = $existing->{$field};
            $exp = $expected[$field];
            $expValue = $exp instanceof \BackedEnum ? $exp->value : $exp;
            $actualValue = $actual instanceof \BackedEnum ? $actual->value : $actual;

            if ((string) $actualValue !== (string) $expValue) {
                $conflicts[] = "{$field} expected ".json_encode($expValue).', got '.json_encode($actualValue);
            }
        }

        // visibility_settings is intentionally excluded from conflict checks:
        // it is administrator-editable state (see FieldDefinitionResource
        // "Показувати в B2B" toggle), and existing bindings are never
        // overwritten by this seeder — no risk of silent data loss.

        return $conflicts;
    }

    private function valuesSemanticallyEqual(mixed $a, mixed $b): bool
    {
        if (is_string($a) && $this->looksLikeJson($a)) {
            $a = json_decode($a, true);
        }

        if (is_string($b) && $this->looksLikeJson($b)) {
            $b = json_decode($b, true);
        }

        return $a == $b;
    }

    private function looksLikeJson(string $value): bool
    {
        return str_starts_with(trim($value), '{') || str_starts_with(trim($value), '[');
    }

    /**
     * Convention: for select-type dynamic attributes, variant_attribute_values.value_text
     * stores the stable option code (e.g. "blue", "m"), never the display label. Labels are
     * resolved from validation_rules.options[].labels at render time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function systemAttributes(): array
    {
        $visibility = fn (bool $admin, bool $b2b): array => [
            'admin' => $admin,
            'b2b' => $b2b,
            'channels' => [],
        ];

        return [
            [
                'code' => 'internal_product_id',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Number,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.id',
                'attribute_group' => 'internal',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 10,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => 'Внутрішній ID товару'],
                'validation_rules' => null,
            ],
            [
                'code' => 'product_name',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.name',
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 20,
                'visibility_settings' => $visibility(true, true),
                'localized_labels' => ['uk' => 'Назва товару'],
                'validation_rules' => null,
            ],
            [
                'code' => 'brand',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.brand',
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 30,
                'visibility_settings' => $visibility(true, true),
                'localized_labels' => ['uk' => 'Бренд'],
                'validation_rules' => null,
            ],
            [
                'code' => 'category',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Relation,
                'storage_path' => 'products.category_id',
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 40,
                'visibility_settings' => $visibility(true, true),
                'localized_labels' => ['uk' => 'Категорія'],
                'validation_rules' => null,
            ],
            [
                'code' => 'description',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::LongText,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.description',
                'attribute_group' => 'descriptions',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 50,
                'visibility_settings' => $visibility(true, true),
                'localized_labels' => ['uk' => 'Опис'],
                'validation_rules' => null,
            ],
            [
                'code' => 'status',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Boolean,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.is_active',
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 60,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => 'Статус'],
                'validation_rules' => null,
            ],
            [
                'code' => 'product_url',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Url,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.product_url',
                'attribute_group' => 'seo',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 70,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => 'URL товару'],
                'validation_rules' => null,
            ],
            [
                'code' => 'sku',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'product_variants.sku',
                'attribute_group' => 'identifiers',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 80,
                'visibility_settings' => $visibility(true, true),
                'localized_labels' => ['uk' => 'SKU'],
                'validation_rules' => null,
            ],
            [
                'code' => 'gtin',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'product_variants.barcode_ean',
                'attribute_group' => 'identifiers',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 90,
                'visibility_settings' => $visibility(true, true),
                'localized_labels' => ['uk' => 'GTIN'],
                'validation_rules' => null,
            ],
            [
                'code' => 'merchant_type',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.merchant_type',
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 120,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => 'Внутрішній тип товару'],
                'validation_rules' => null,
            ],
            [
                'code' => 'weight_netto',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Decimal,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.weight_netto',
                'attribute_group' => 'logistics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 130,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => 'Вага нетто'],
                'validation_rules' => null,
            ],
            [
                'code' => 'weight_brutto',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Decimal,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.weight_brutto',
                'attribute_group' => 'logistics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 140,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => 'Вага брутто'],
                'validation_rules' => null,
            ],
            [
                'code' => 'volume_m3',
                'workspace_id' => null,
                'scope' => AttributeScope::System,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Decimal,
                'storage_type' => AttributeStorageType::Column,
                'storage_path' => 'products.volume_m3',
                'attribute_group' => 'logistics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => true,
                'status' => AttributeStatus::Active,
                'sort_order' => 150,
                'visibility_settings' => $visibility(true, false),
                'localized_labels' => ['uk' => "Об'єм, м³"],
                'validation_rules' => null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function platformLibraryAttributes(): array
    {
        return [
            [
                'code' => 'color',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Select,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'characteristics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 100,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['uk' => 'Колір'],
                'validation_rules' => [
                    'options' => [
                        ['code' => 'blue', 'labels' => ['uk' => 'Синій']],
                        ['code' => 'pink', 'labels' => ['uk' => 'Рожевий']],
                    ],
                ],
            ],
            [
                'code' => 'size',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Select,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'characteristics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 110,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['uk' => 'Розмір'],
                'validation_rules' => [
                    'options' => [
                        ['code' => 'm', 'labels' => ['uk' => 'M']],
                    ],
                ],
            ],
            [
                'code' => 'condition',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Select,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 120,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Condition', 'uk' => 'Стан', 'ru' => 'Состояние'],
                'validation_rules' => [
                    'options' => [
                        ['code' => 'new', 'labels' => ['en' => 'New', 'uk' => 'Новий', 'ru' => 'Новый']],
                        ['code' => 'used', 'labels' => ['en' => 'Used', 'uk' => 'Вживаний', 'ru' => 'Б/у']],
                        ['code' => 'refurbished', 'labels' => ['en' => 'Refurbished', 'uk' => 'Відновлений', 'ru' => 'Восстановленный']],
                    ],
                ],
            ],
            [
                'code' => 'short_description',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'descriptions',
                'is_localizable' => true,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 130,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Short Description', 'uk' => 'Короткий опис', 'ru' => 'Краткое описание'],
                'validation_rules' => null,
            ],
            [
                'code' => 'material',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'characteristics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 140,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Material', 'uk' => 'Матеріал', 'ru' => 'Материал'],
                'validation_rules' => null,
            ],
            [
                'code' => 'country_of_origin',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'characteristics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 150,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Country of Origin', 'uk' => 'Країна походження', 'ru' => 'Страна происхождения'],
                'validation_rules' => null,
            ],
            [
                'code' => 'manufacturer',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 160,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Manufacturer', 'uk' => 'Виробник', 'ru' => 'Производитель'],
                'validation_rules' => null,
            ],
            [
                'code' => 'model',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'basic_information',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 170,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Model', 'uk' => 'Модель', 'ru' => 'Модель'],
                'validation_rules' => null,
            ],
            [
                'code' => 'compatibility',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::LongText,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'characteristics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 180,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Compatibility', 'uk' => 'Сумісність', 'ru' => 'Совместимость'],
                'validation_rules' => null,
            ],
            [
                'code' => 'battery_type',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::Text,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'characteristics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 190,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['en' => 'Battery Type', 'uk' => 'Тип батареї', 'ru' => 'Тип батареи'],
                'validation_rules' => null,
            ],
            [
                'code' => 'shipping_required',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Boolean,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'logistics',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 160,
                'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
                'localized_labels' => ['uk' => 'Потребує доставки'],
                'validation_rules' => null,
            ],
            [
                'code' => 'backorder_policy',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'variant',
                'data_type' => AttributeDataType::Select,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'availability',
                'is_localizable' => false,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => true,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 170,
                'visibility_settings' => ['admin' => true, 'b2b' => false, 'channels' => []],
                'localized_labels' => ['uk' => 'Замовлення за відсутності'],
                'validation_rules' => [
                    'options' => [
                        ['code' => 'deny', 'labels' => ['uk' => 'Не дозволяти']],
                        ['code' => 'continue', 'labels' => ['uk' => 'Дозволяти']],
                    ],
                ],
            ],
            [
                'code' => 'technical_characteristics',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::LongText,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'descriptions',
                'is_localizable' => true,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 180,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['uk' => 'Технічні характеристики'],
                'validation_rules' => null,
            ],
            [
                'code' => 'instructions',
                'workspace_id' => null,
                'scope' => AttributeScope::PlatformLibrary,
                'value_level' => 'product',
                'data_type' => AttributeDataType::LongText,
                'storage_type' => AttributeStorageType::Dynamic,
                'storage_path' => null,
                'attribute_group' => 'descriptions',
                'is_localizable' => true,
                'is_multi_value' => false,
                'is_required' => false,
                'is_filterable' => false,
                'is_sortable' => false,
                'status' => AttributeStatus::Active,
                'sort_order' => 190,
                'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
                'localized_labels' => ['uk' => 'Інструкція'],
                'validation_rules' => null,
            ],
        ];
    }
}
