<?php

namespace App\Filament\Resources\ProductResource\Support;

use App\Enums\AttributeDataType;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\VariantFieldValue;
use App\Services\Catalog\GovernedProductVariantColumnEligibility;
use App\Services\Catalog\GovernedProductVariantColumnMutationService;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Services\ProductStructure\ProductCompletenessService;
use App\Services\ProductStructure\ProductStructureStoredValueReader;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ProductStructureEditor
{
    public function __construct(
        private readonly GovernedDynamicFieldValueWriter $dynamicWriter,
        private readonly GovernedProductVariantColumnMutationService $columnWriter,
        private readonly GovernedProductVariantColumnEligibility $columnEligibility,
        private readonly ProductCompletenessService $completeness,
        private readonly ProductStructureStoredValueReader $storedValueReader,
    ) {}

    public function schema(Product $product, string $locale = 'uk'): array
    {
        [$product, $groups, $variants, $projection] = $this->context($product, $locale);
        $projectionByGroup = collect($projection->groups)->keyBy('groupPlacementId');
        $groupOptions = ['all' => 'Усі групи'];
        foreach ($groups as $group) {
            $groupProjection = $projectionByGroup->get((string) $group->id);
            if ($groupProjection === null || ! $groupProjection->isActive) {
                continue;
            }
            $groupOptions[(string) $group->id] = (string) ($group->attributeGroup?->localized_labels[$locale]
                ?? $group->attributeGroup?->localized_labels['uk']
                ?? $group->attributeGroup?->localized_labels['en']
                ?? $group->attributeGroup?->code
                ?? 'Група');
        }
        $sections = [
            Section::make('Навігація по полях')->schema([
                TextInput::make('structure_search')
                    ->label('Пошук')
                    ->placeholder('Назва поля або групи')
                    ->live()
                    ->dehydrated(false),
                Select::make('structure_group')
                    ->label('Група')
                    ->options($groupOptions)
                    ->default('all')
                    ->live()
                    ->dehydrated(false),
                Select::make('structure_filter')
                    ->label('Показати')
                    ->options([
                        'all' => 'Усі',
                        'empty' => 'Порожні',
                        'required' => 'Обов’язкові',
                        'incomplete' => 'Незаповнені обов’язкові',
                        'additional' => 'Додаткові дані',
                    ])
                    ->default('all')
                    ->live()
                    ->dehydrated(false),
            ])->columns(3),
        ];

        foreach ($groups as $group) {
            $groupProjection = $projectionByGroup->get((string) $group->id);
            if ($groupProjection === null || ! $groupProjection->isActive) {
                continue;
            }

            $groupLabel = (string) ($group->attributeGroup?->localized_labels[$locale]
                ?? $group->attributeGroup?->localized_labels['uk']
                ?? $group->attributeGroup?->localized_labels['en']
                ?? $group->attributeGroup?->code
                ?? 'Група');
            $productComponents = [];
            $variantSections = [];
            $fieldMeta = [];

            foreach ($group->fieldPlacements as $placement) {
                $binding = $placement->fieldBinding;
                $definition = $binding?->fieldDefinition;
                if (! $binding instanceof FieldBinding
                    || ! $definition instanceof FieldDefinition
                    || ! $this->admitted($product, $binding, $definition)) {
                    continue;
                }

                $required = (bool) $placement->required_for_completeness;
                $label = $this->fieldLabel($definition, $locale, $required);
                if ($binding->object_type === FieldObjectType::Product) {
                    $path = "product_values.{$binding->id}";
                    $incomplete = in_array((string) $binding->id, $groupProjection->missingProductBindingIds, true);
                    $productComponents[] = $this->component($path, $label, $binding, $definition, $locale)
                        ->visible(fn (Get $get): bool => $this->fieldVisible(
                            $get, $label, $groupLabel, $required, $incomplete, [$path],
                        ));
                    $fieldMeta[] = compact('label', 'required', 'incomplete') + ['paths' => [$path]];

                    continue;
                }

                if ($binding->object_type !== FieldObjectType::ProductVariant || $variants->isEmpty()) {
                    continue;
                }

                $paths = [];
                $variantComponents = [];
                foreach ($variants as $variant) {
                    $path = "variant_values.{$variant->id}.{$binding->id}";
                    $paths[] = $path;
                    $variantComponents[] = $this->component(
                        $path,
                        $variant->sku ?: 'Варіант #'.$variant->id,
                        $binding,
                        $definition,
                        $locale,
                    );
                }
                $incomplete = collect($groupProjection->missingVariantCells)
                    ->contains(fn (array $cell): bool => (string) $cell['field_binding_id'] === (string) $binding->id);
                $variantSections[] = Section::make($label)
                    ->description('Значення для активних варіантів товару')
                    ->schema([Grid::make(3)->schema($variantComponents)])
                    ->visible(fn (Get $get): bool => $this->fieldVisible(
                        $get, $label, $groupLabel, $required, $incomplete, $paths,
                    ))
                    ->collapsible();
                $fieldMeta[] = compact('label', 'required', 'incomplete', 'paths');
            }

            $schema = [];
            if ($productComponents !== []) {
                $schema[] = Grid::make(2)->schema($productComponents);
            }
            array_push($schema, ...$variantSections);

            $sections[] = Section::make($groupLabel)
                ->description(sprintf(
                    'Повнота: %d%% (%d/%d)',
                    $groupProjection->percentage,
                    $groupProjection->filledCount,
                    $groupProjection->requiredCount,
                ))
                ->schema($schema)
                ->visible(fn (Get $get): bool => $this->groupVisible($get, (string) $group->id, $groupLabel, $fieldMeta))
                ->collapsible();
        }

        $additional = $this->additionalDataComponents($product, $groups, $locale);
        $sections[] = Section::make('Додаткові дані')
            ->description('Збережені значення, які не входять до поточного типу товару. Вони не впливають на повноту.')
            ->schema($additional !== []
                ? $additional
                : [Placeholder::make('no_additional_data')->content('Додаткових збережених значень немає.')])
            ->visible(fn (Get $get): bool => ($get('structure_filter') ?? 'all') === 'additional')
            ->collapsible();

        return $sections;
    }

    public function fill(Product $product, string $locale = 'uk'): array
    {
        [$product, $groups, $variants, $projection] = $this->context($product, $locale);
        $activeGroupIds = collect($projection->groups)
            ->filter(fn ($group): bool => $group->isActive)
            ->pluck('groupPlacementId')
            ->all();
        $placements = $groups
            ->whereIn('id', $activeGroupIds)
            ->flatMap(fn (ProductTypeGroupPlacement $group) => $group->fieldPlacements);

        $productBindingIds = $placements
            ->filter(fn ($placement): bool => $placement->fieldBinding?->object_type === FieldObjectType::Product)
            ->pluck('field_binding_id')->unique()->values();
        $variantBindingIds = $placements
            ->filter(fn ($placement): bool => $placement->fieldBinding?->object_type === FieldObjectType::ProductVariant)
            ->pluck('field_binding_id')->unique()->values();

        $productSlots = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereIn('field_binding_id', $productBindingIds)
            ->get()->keyBy('field_binding_id');
        $variantSlots = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereIn('variant_id', $variants->pluck('id'))
            ->whereIn('field_binding_id', $variantBindingIds)
            ->get()->keyBy(fn (VariantFieldValue $slot): string => $slot->variant_id.':'.$slot->field_binding_id);

        $data = ['product_values' => [], 'variant_values' => []];
        foreach ($placements as $placement) {
            $binding = $placement->fieldBinding;
            $definition = $binding?->fieldDefinition;
            if (! $binding instanceof FieldBinding
                || ! $definition instanceof FieldDefinition
                || ! $this->admitted($product, $binding, $definition)) {
                continue;
            }

            if ($binding->object_type === FieldObjectType::Product) {
                $data['product_values'][$binding->id] = $this->readValue(
                    $product,
                    $binding,
                    $definition,
                    $productSlots->get($binding->id),
                    $locale,
                );

                continue;
            }

            if ($binding->object_type === FieldObjectType::ProductVariant) {
                foreach ($variants as $variant) {
                    $data['variant_values'][$variant->id][$binding->id] = $this->readValue(
                        $variant,
                        $binding,
                        $definition,
                        $variantSlots->get($variant->id.':'.$binding->id),
                        $locale,
                    );
                }
            }
        }

        return $data;
    }

    public function save(Product $product, array $data, string $locale = 'uk'): void
    {
        [$product, $groups, $variants, $projection] = $this->context($product, $locale);
        $activeGroupIds = collect($projection->groups)
            ->filter(fn ($group): bool => $group->isActive)
            ->pluck('groupPlacementId')
            ->all();
        $placements = $groups
            ->whereIn('id', $activeGroupIds)
            ->flatMap(fn (ProductTypeGroupPlacement $group) => $group->fieldPlacements);

        DB::transaction(function () use ($product, $variants, $placements, $data, $locale): void {
            foreach ($placements as $placement) {
                $binding = $placement->fieldBinding;
                $definition = $binding?->fieldDefinition;
                if (! $binding instanceof FieldBinding
                    || ! $definition instanceof FieldDefinition
                    || ! $this->admitted($product, $binding, $definition)
                    || ! $this->editable($binding, $definition)) {
                    continue;
                }

                if ($binding->object_type === FieldObjectType::Product) {
                    $path = "product_values.{$binding->id}";
                    if (! Arr::has($data, $path)) {
                        continue;
                    }
                    $this->writeValue($product, $binding, $definition, data_get($data, $path), $locale);

                    continue;
                }

                if ($binding->object_type === FieldObjectType::ProductVariant) {
                    foreach ($variants as $variant) {
                        $path = "variant_values.{$variant->id}.{$binding->id}";
                        if (! Arr::has($data, $path)) {
                            continue;
                        }
                        $this->writeValue($variant, $binding, $definition, data_get($data, $path), $locale);
                    }
                }
            }
        });
    }

    /** @param list<array{label: string, required: bool, incomplete: bool, paths: list<string>}> $fieldMeta */
    private function groupVisible(Get $get, string $groupId, string $groupLabel, array $fieldMeta): bool
    {
        if (($get('structure_filter') ?? 'all') === 'additional') {
            return false;
        }
        $selectedGroup = (string) ($get('structure_group') ?? 'all');
        if ($selectedGroup !== 'all' && $selectedGroup !== $groupId) {
            return false;
        }

        foreach ($fieldMeta as $meta) {
            if ($this->fieldVisible(
                $get,
                $meta['label'],
                $groupLabel,
                $meta['required'],
                $meta['incomplete'],
                $meta['paths'],
            )) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $paths */
    private function fieldVisible(
        Get $get,
        string $label,
        string $groupLabel,
        bool $required,
        bool $incomplete,
        array $paths,
    ): bool {
        $filter = (string) ($get('structure_filter') ?? 'all');
        if ($filter === 'additional') {
            return false;
        }

        $search = mb_strtolower(trim((string) ($get('structure_search') ?? '')));
        if ($search !== '') {
            $haystack = mb_strtolower($label.' '.$groupLabel);
            if (! str_contains($haystack, $search)) {
                return false;
            }
        }

        return match ($filter) {
            'all' => true,
            'required' => $required,
            'incomplete' => $required && $incomplete,
            'empty' => collect($paths)->contains(fn (string $path): bool => $this->stateIsEmpty($get($path))),
            default => true,
        };
    }

    private function stateIsEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @return array<int, Placeholder> */
    private function additionalDataComponents(Product $product, $groups, string $locale): array
    {
        $currentBindingIds = $groups
            ->flatMap(fn (ProductTypeGroupPlacement $group) => $group->fieldPlacements)
            ->pluck('field_binding_id')
            ->map('strval')
            ->unique()
            ->values()
            ->all();
        $components = [];

        $productSlots = ProductFieldValue::withoutWorkspaceScope()
            ->with([
                'fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->when($currentBindingIds !== [], fn ($query) => $query->whereNotIn('field_binding_id', $currentBindingIds))
            ->orderBy('field_binding_id')
            ->get();

        foreach ($productSlots as $slot) {
            $binding = $slot->fieldBinding;
            $definition = $binding?->fieldDefinition;
            if (! $binding instanceof FieldBinding || ! $definition instanceof FieldDefinition) {
                continue;
            }
            $value = $this->dynamicAdditionalValue($definition, $slot, $locale);
            if ($this->stateIsEmpty($value)) {
                continue;
            }
            $components[] = Placeholder::make('additional_product_'.md5((string) $slot->id))
                ->label($definition->localizedLabel($locale).' — Товар')
                ->content($this->formatAdditionalValue($value));
        }

        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get();
        $variantSlots = VariantFieldValue::withoutWorkspaceScope()
            ->with([
                'fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('workspace_id', $product->workspace_id)
            ->whereIn('variant_id', $variants->pluck('id'))
            ->when($currentBindingIds !== [], fn ($query) => $query->whereNotIn('field_binding_id', $currentBindingIds))
            ->orderBy('variant_id')
            ->orderBy('field_binding_id')
            ->get();
        $variantsById = $variants->keyBy('id');

        foreach ($variantSlots as $slot) {
            $binding = $slot->fieldBinding;
            $definition = $binding?->fieldDefinition;
            $variant = $variantsById->get($slot->variant_id);
            if (! $binding instanceof FieldBinding || ! $definition instanceof FieldDefinition || ! $variant instanceof ProductVariant) {
                continue;
            }
            $value = $this->dynamicAdditionalValue($definition, $slot, $locale);
            if ($this->stateIsEmpty($value)) {
                continue;
            }
            $components[] = Placeholder::make('additional_variant_'.md5((string) $slot->id))
                ->label($definition->localizedLabel($locale).' — Варіант '.($variant->sku ?: '#'.$variant->id))
                ->content($this->formatAdditionalValue($value));
        }

        $canonicalBindings = FieldBinding::withoutWorkspaceScope()
            ->with(['fieldDefinition' => fn ($query) => $query->withoutGlobalScopes()])
            ->whereIn('object_type', [FieldObjectType::Product, FieldObjectType::ProductVariant])
            ->whereIn('storage_type', [AttributeStorageType::Column, AttributeStorageType::Relation])
            ->where(function ($query) use ($product): void {
                $query->whereNull('workspace_id')->orWhere('workspace_id', $product->workspace_id);
            })
            ->when($currentBindingIds !== [], fn ($query) => $query->whereNotIn('id', $currentBindingIds))
            ->orderBy('sort_order')
            ->get();

        foreach ($canonicalBindings as $binding) {
            $definition = $binding->fieldDefinition;
            if (! $definition instanceof FieldDefinition) {
                continue;
            }
            $targets = $binding->object_type === FieldObjectType::Product ? collect([$product]) : $variants;
            foreach ($targets as $target) {
                $read = $this->storedValueReader->read($target, $binding, $definition);
                if (! $read['present']) {
                    continue;
                }
                $owner = $target instanceof Product
                    ? 'Товар'
                    : 'Варіант '.($target->sku ?: '#'.$target->id);
                $components[] = Placeholder::make('additional_canonical_'.md5($binding->id.':'.$target->id))
                    ->label($definition->localizedLabel($locale).' — '.$owner)
                    ->content($this->formatAdditionalValue($read['value']));
            }
        }

        return $components;
    }

    private function dynamicAdditionalValue(FieldDefinition $definition, mixed $slot, string $locale): mixed
    {
        try {
            return $this->dynamicWriter->storedSlotValue(
                $definition,
                $slot,
                $definition->is_localizable ? $locale : null,
            );
        } catch (\Throwable) {
            return $slot->value_jsonb ?? $slot->value_text ?? $slot->value_num;
        }
    }

    private function formatAdditionalValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Так' : 'Ні';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        return (string) $value;
    }

    private function context(Product $product, string $locale): array
    {
        $product = Product::withoutWorkspaceScope()->where('workspace_id', $product->workspace_id)->findOrFail($product->id);
        $groups = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_type_id', $product->product_type_id)
            ->with([
                'attributeGroup' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldPlacements' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order')->orderBy('id'),
                'fieldPlacements.fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldPlacements.fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('sort_order')->orderBy('id')->get();
        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')->get();

        return [$product, $groups, $variants, $this->completeness->project($product, $locale)];
    }

    private function component(
        string $path,
        string $label,
        FieldBinding $binding,
        FieldDefinition $definition,
        string $locale,
    ): mixed {
        if (! $this->editable($binding, $definition)) {
            return Placeholder::make(str_replace('.', '_', $path).'_readonly')
                ->label($label)
                ->content('Лише перегляд: тип або storage не підтримує governed editing.');
        }

        return match ($definition->data_type) {
            AttributeDataType::LongText => Textarea::make($path)->label($label)->rows(3),
            AttributeDataType::Number,
            AttributeDataType::Decimal => TextInput::make($path)->label($label)->numeric(),
            AttributeDataType::Boolean => Select::make($path)->label($label)->options(['1' => 'Так', '0' => 'Ні'])->placeholder('Не задано'),
            AttributeDataType::Date => DatePicker::make($path)->label($label),
            AttributeDataType::Url => TextInput::make($path)->label($label)->url(),
            AttributeDataType::Select => Select::make($path)->label($label)->options($this->options($definition, $locale))->searchable(),
            AttributeDataType::MultiSelect => Select::make($path)->label($label)->options($this->options($definition, $locale))->multiple()->searchable(),
            default => TextInput::make($path)->label($label),
        };
    }

    private function options(FieldDefinition $definition, string $locale): array
    {
        $options = $definition->validation_rules['options'] ?? [];
        if (! is_array($options)) {
            return [];
        }

        $result = [];
        foreach ($options as $option) {
            if (! is_array($option) || ! is_string($option['code'] ?? null) || $option['code'] === '') {
                continue;
            }
            $labels = is_array($option['labels'] ?? null) ? $option['labels'] : [];
            $result[$option['code']] = (string) ($labels[$locale] ?? $labels['uk'] ?? $labels['en'] ?? $option['code']);
        }

        return $result;
    }

    private function fieldLabel(FieldDefinition $definition, string $locale, bool $required): string
    {
        return $definition->localizedLabel($locale).($required ? ' *' : '');
    }

    private function admitted(Product $product, FieldBinding $binding, FieldDefinition $definition): bool
    {
        if ($binding->status !== AttributeStatus::Active || $definition->status !== AttributeStatus::Active) {
            return false;
        }
        if ($binding->workspace_id !== null && (string) $binding->workspace_id !== (string) $product->workspace_id) {
            return false;
        }

        return ($definition->workspace_id ?? null) === ($binding->workspace_id ?? null);
    }

    private function editable(FieldBinding $binding, FieldDefinition $definition): bool
    {
        if ($binding->storage_type === AttributeStorageType::Column) {
            return $this->columnEligibility->matchingRule($binding, $definition) !== null;
        }

        if ($binding->storage_type !== AttributeStorageType::Dynamic) {
            return false;
        }

        return in_array($definition->data_type, [
            AttributeDataType::Text,
            AttributeDataType::LongText,
            AttributeDataType::Number,
            AttributeDataType::Decimal,
            AttributeDataType::Boolean,
            AttributeDataType::Date,
            AttributeDataType::Url,
            AttributeDataType::Select,
            AttributeDataType::MultiSelect,
        ], true);
    }

    private function readValue(
        Product|ProductVariant $target,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $slot,
        string $locale,
    ): mixed {
        if ($binding->storage_type === AttributeStorageType::Dynamic) {
            $value = $this->dynamicWriter->storedSlotValue(
                $definition,
                $slot,
                $definition->is_localizable ? $locale : null,
            );

            if ($definition->data_type === AttributeDataType::Boolean && is_bool($value)) {
                return $value ? '1' : '0';
            }

            return $value;
        }

        $rule = $this->columnEligibility->matchingRule($binding, $definition);

        return $rule === null ? null : $target->getAttribute($rule['column']);
    }

    private function writeValue(
        Product|ProductVariant $target,
        FieldBinding $binding,
        FieldDefinition $definition,
        mixed $value,
        string $locale,
    ): void {
        $targetType = $target instanceof Product ? FieldObjectType::Product : FieldObjectType::ProductVariant;
        $clear = $value === null || ($definition->data_type === AttributeDataType::MultiSelect && $value === []);
        if (! $clear && $definition->data_type === AttributeDataType::Boolean) {
            $value = match ($value) {
                true, 1, '1' => true,
                false, 0, '0' => false,
                default => $value,
            };
        }

        if ($binding->storage_type === AttributeStorageType::Dynamic) {
            if ($clear) {
                $this->dynamicWriter->clear(
                    (string) $target->workspace_id,
                    $targetType,
                    $target->id,
                    (string) $binding->id,
                    $definition->is_localizable ? $locale : null,
                );

                return;
            }

            $this->dynamicWriter->set(
                (string) $target->workspace_id,
                $targetType,
                $target->id,
                (string) $binding->id,
                $value,
                $definition->is_localizable ? $locale : null,
            );

            return;
        }

        if ($clear) {
            $this->columnWriter->clear(
                (string) $target->workspace_id,
                $targetType,
                $target->id,
                (string) $binding->id,
            );

            return;
        }

        $this->columnWriter->set(
            (string) $target->workspace_id,
            $targetType,
            $target->id,
            (string) $binding->id,
            $value,
        );
    }
}
