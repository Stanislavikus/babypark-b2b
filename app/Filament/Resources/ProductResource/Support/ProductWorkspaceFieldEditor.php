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
use App\Models\ProductType;
use App\Models\ProductTypeFieldPlacement;
use App\Models\ProductTypeGroupPlacement;
use App\Models\ProductVariant;
use App\Models\ProductVariantAxis;
use App\Models\VariantFieldValue;
use App\Services\Fields\FieldValueWriteResult;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use App\Services\ProductStructure\ProductOptionalGroupStateResolver;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductWorkspaceFieldEditor
{
    private const SUPPORTED_TYPES = [
        AttributeDataType::Text,
        AttributeDataType::LongText,
        AttributeDataType::Number,
        AttributeDataType::Decimal,
        AttributeDataType::Boolean,
        AttributeDataType::Date,
        AttributeDataType::Select,
        AttributeDataType::MultiSelect,
        AttributeDataType::Url,
    ];

    public function __construct(
        private readonly GovernedDynamicFieldValueWriter $writer,
        private readonly ProductOptionalGroupStateResolver $optionalGroupStateResolver,
    ) {}

    public function hasEditableFields(Product $product): bool
    {
        return $this->projection($product)['coordinates'] !== [];
    }

    /** @return array<int, mixed> */
    public function schema(Product $product): array
    {
        $projection = $this->projection($product);
        $components = [Hidden::make('snapshot_token')];

        if ($projection['groups'] === []) {
            $components[] = Placeholder::make('no_product_fields')
                ->hiddenLabel()
                ->content('Для поточного типу товару немає редагованих динамічних полів.');

            return $components;
        }

        foreach ($projection['groups'] as $group) {
            $groupComponents = [];
            $productPlacements = $group['placements']
                ->filter(fn (ProductTypeFieldPlacement $placement): bool => $placement->fieldBinding?->object_type === FieldObjectType::Product)
                ->values();
            $variantPlacements = $group['placements']
                ->filter(fn (ProductTypeFieldPlacement $placement): bool => $placement->fieldBinding?->object_type === FieldObjectType::ProductVariant)
                ->values();

            if ($productPlacements->isNotEmpty()) {
                $groupComponents[] = Grid::make(2)
                    ->schema($productPlacements
                        ->map(fn (ProductTypeFieldPlacement $placement) => $this->fieldComponent(
                            $placement,
                            'values.product.'.(string) $placement->field_binding_id,
                        ))
                        ->all());
            }

            if ($variantPlacements->isNotEmpty() && $projection['variants']->isNotEmpty()) {
                if ($projection['variants']->count() === 1) {
                    /** @var ProductVariant $variant */
                    $variant = $projection['variants']->first();
                    $groupComponents[] = Grid::make(2)
                        ->schema($variantPlacements
                            ->map(fn (ProductTypeFieldPlacement $placement) => $this->fieldComponent(
                                $placement,
                                'values.variants.'.(string) $variant->id.'.'.(string) $placement->field_binding_id,
                            ))
                            ->all());
                } else {
                    $groupComponents[] = Grid::make(2)
                        ->schema($projection['variants']
                            ->map(function (ProductVariant $variant) use ($variantPlacements): Section {
                                $label = filled($variant->sku)
                                    ? 'SKU '.$variant->sku
                                    : 'Варіант #'.$variant->id;

                                return Section::make($label)
                                    ->compact()
                                    ->schema($variantPlacements
                                        ->map(fn (ProductTypeFieldPlacement $placement) => $this->fieldComponent(
                                            $placement,
                                            'values.variants.'.(string) $variant->id.'.'.(string) $placement->field_binding_id,
                                        ))
                                        ->all());
                            })
                            ->all());
                }
            }

            $required = $group['placements']->where('required_for_completeness', true)->count();
            $description = $required > 0
                ? $required.' обов’язкових для повноти · обов’язкові показані першими'
                : 'Додаткові поля цього типу товару';

            $components[] = Section::make($group['label'])
                ->description($description)
                ->schema($groupComponents);
        }

        return $components;
    }

    /** @return array<string, mixed> */
    public function formState(Product $product): array
    {
        $projection = $this->projection($product);
        $values = ['product' => [], 'variants' => []];
        $expected = [];
        $productBindingIds = collect($projection['coordinates'])
            ->filter(fn (array $coordinate): bool => $coordinate['target_type'] === FieldObjectType::Product)
            ->pluck('binding.id')
            ->unique()
            ->values();
        $variantBindingIds = collect($projection['coordinates'])
            ->filter(fn (array $coordinate): bool => $coordinate['target_type'] === FieldObjectType::ProductVariant)
            ->pluck('binding.id')
            ->unique()
            ->values();
        $productSlots = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereIn('field_binding_id', $productBindingIds)
            ->get()
            ->keyBy('field_binding_id');
        $variantSlots = VariantFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereIn('variant_id', $projection['variants']->pluck('id'))
            ->whereIn('field_binding_id', $variantBindingIds)
            ->get()
            ->keyBy(fn (VariantFieldValue $slot): string => $slot->variant_id.':'.$slot->field_binding_id);

        foreach ($projection['coordinates'] as $key => $coordinate) {
            $slot = $coordinate['target_type'] === FieldObjectType::Product
                ? $productSlots->get((string) $coordinate['binding']->id)
                : $variantSlots->get($coordinate['target_id'].':'.$coordinate['binding']->id);
            $value = $this->currentValue($coordinate['definition'], $slot, $projection['locale']);
            $expected[$key] = $value;

            if ($coordinate['target_type'] === FieldObjectType::Product) {
                $values['product'][(string) $coordinate['binding']->id] = $value;

                continue;
            }

            $variantId = (string) $coordinate['target_id'];
            $values['variants'][$variantId][(string) $coordinate['binding']->id] = $value;
        }

        $snapshot = [
            'workspace_id' => (string) $product->workspace_id,
            'product_id' => (int) $product->id,
            'product_type_id' => (string) $projection['product_type']->id,
            'structure_revision' => (int) $projection['product_type']->structure_revision,
            'locale' => $projection['locale'],
            'coordinates' => array_keys($projection['coordinates']),
            'expected' => $expected,
        ];

        return [
            'values' => $values,
            'snapshot_token' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array{changed:int, cleared:int, unchanged:int} */
    public function apply(Product $product, array $data): array
    {
        $snapshot = $this->decodeSnapshot($data['snapshot_token'] ?? null);
        $projection = $this->projection($product);

        $this->assertSnapshotMatches($product, $projection, $snapshot);

        $counts = ['changed' => 0, 'cleared' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($product, $data, $snapshot, $projection, &$counts): void {
            foreach ($projection['coordinates'] as $key => $coordinate) {
                $binding = $coordinate['binding'];
                $definition = $coordinate['definition'];
                $expected = $snapshot['expected'][$key] ?? null;
                $statePath = $coordinate['target_type'] === FieldObjectType::Product
                    ? 'values.product.'.(string) $binding->id
                    : 'values.variants.'.(string) $coordinate['target_id'].'.'.(string) $binding->id;

                if (! Arr::has($data, $statePath)) {
                    throw ProductWorkspaceFieldEditStaleException::snapshotMissing();
                }

                $value = data_get($data, $statePath);
                $locale = $definition->is_localizable ? $projection['locale'] : null;

                if ($this->isEmptyValue($definition, $value)) {
                    $result = $this->writer->clearIfCurrentValue(
                        workspaceId: (string) $product->workspace_id,
                        targetType: $coordinate['target_type'],
                        targetId: $coordinate['target_id'],
                        fieldBindingId: (string) $binding->id,
                        expectedCurrentValue: $expected,
                        locale: $locale,
                    );
                    $counts[$result->status === FieldValueWriteResult::NoOp ? 'unchanged' : 'cleared']++;

                    continue;
                }

                $result = $this->writer->setIfCurrentValue(
                    workspaceId: (string) $product->workspace_id,
                    targetType: $coordinate['target_type'],
                    targetId: $coordinate['target_id'],
                    fieldBindingId: (string) $binding->id,
                    expectedCurrentValue: $expected,
                    value: $value,
                    locale: $locale,
                );
                $counts[$result->status === FieldValueWriteResult::NoOp ? 'unchanged' : 'changed']++;
            }
        });

        return $counts;
    }

    /**
     * @return array{
     *   product_type: ProductType,
     *   locale: string,
     *   variants: Collection<int, ProductVariant>,
     *   groups: list<array{label:string,placements:Collection<int, ProductTypeFieldPlacement>}>,
     *   coordinates: array<string, array{workspace_id:string,target_type:FieldObjectType,target_id:int,binding:FieldBinding,definition:FieldDefinition}>
     * }
     */
    private function projection(Product $product): array
    {
        $locale = app()->getLocale();
        $productType = ProductType::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->whereKey($product->product_type_id)
            ->firstOrFail();
        $variants = ProductVariant::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
        $declaredAxisBindingIds = ProductVariantAxis::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->pluck('field_binding_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $groupPlacements = ProductTypeGroupPlacement::withoutWorkspaceScope()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_type_id', $productType->id)
            ->with([
                'attributeGroup' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldPlacements' => fn ($query) => $query->withoutGlobalScopes()
                    ->orderByDesc('required_for_completeness')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'fieldPlacements.fieldBinding' => fn ($query) => $query->withoutGlobalScopes(),
                'fieldPlacements.fieldBinding.fieldDefinition' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $groups = [];
        $coordinates = [];

        foreach ($groupPlacements as $groupPlacement) {
            if ($groupPlacement->attributeGroup?->status !== 'active'
                || ! $this->optionalGroupStateResolver->isActive($product, $groupPlacement)) {
                continue;
            }

            $placements = $groupPlacement->fieldPlacements
                ->filter(fn (ProductTypeFieldPlacement $placement): bool => ! in_array(
                    (string) $placement->field_binding_id,
                    $declaredAxisBindingIds,
                    true,
                ))
                ->filter(fn (ProductTypeFieldPlacement $placement): bool => $this->placementIsEditable(
                    (string) $product->workspace_id,
                    $placement,
                ))
                ->values();

            if ($placements->isEmpty()) {
                continue;
            }

            $groups[] = [
                'label' => (string) (
                    $groupPlacement->attributeGroup?->localized_labels[$locale]
                    ?? $groupPlacement->attributeGroup?->localized_labels['uk']
                    ?? $groupPlacement->attributeGroup?->localized_labels['en']
                    ?? $groupPlacement->attributeGroup?->code
                    ?? 'Поля товару'
                ),
                'placements' => $placements,
            ];

            foreach ($placements as $placement) {
                /** @var FieldBinding $binding */
                $binding = $placement->fieldBinding;
                /** @var FieldDefinition $definition */
                $definition = $binding->fieldDefinition;

                if ($binding->object_type === FieldObjectType::Product) {
                    $key = $this->coordinateKey(FieldObjectType::Product, (int) $product->id, (string) $binding->id);
                    $coordinates[$key] = [
                        'workspace_id' => (string) $product->workspace_id,
                        'target_type' => FieldObjectType::Product,
                        'target_id' => (int) $product->id,
                        'binding' => $binding,
                        'definition' => $definition,
                    ];

                    continue;
                }

                foreach ($variants as $variant) {
                    $key = $this->coordinateKey(FieldObjectType::ProductVariant, (int) $variant->id, (string) $binding->id);
                    $coordinates[$key] = [
                        'workspace_id' => (string) $product->workspace_id,
                        'target_type' => FieldObjectType::ProductVariant,
                        'target_id' => (int) $variant->id,
                        'binding' => $binding,
                        'definition' => $definition,
                    ];
                }
            }
        }

        ksort($coordinates);

        return [
            'product_type' => $productType,
            'locale' => $locale,
            'variants' => $variants,
            'groups' => $groups,
            'coordinates' => $coordinates,
        ];
    }

    private function placementIsEditable(string $workspaceId, ProductTypeFieldPlacement $placement): bool
    {
        $binding = $placement->fieldBinding;
        $definition = $binding?->fieldDefinition;

        if (! $binding instanceof FieldBinding || ! $definition instanceof FieldDefinition) {
            return false;
        }

        if ($binding->workspace_id !== null && (string) $binding->workspace_id !== $workspaceId) {
            return false;
        }

        if (($definition->workspace_id ?? null) !== ($binding->workspace_id ?? null)) {
            return false;
        }

        if ($binding->status !== AttributeStatus::Active
            || $definition->status !== AttributeStatus::Active
            || $binding->storage_type !== AttributeStorageType::Dynamic
            || ! in_array($binding->object_type, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)
            || ! in_array($definition->data_type, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        return (bool) data_get($binding->visibility_settings, 'admin', false);
    }

    private function fieldComponent(ProductTypeFieldPlacement $placement, string $statePath): mixed
    {
        /** @var FieldDefinition $definition */
        $definition = $placement->fieldBinding->fieldDefinition;
        $label = $definition->localizedLabel(app()->getLocale());
        $helper = $placement->required_for_completeness
            ? 'Обов’язкове для повноти даних'
            : $definition->description;

        $component = match ($definition->data_type) {
            AttributeDataType::Text => TextInput::make($statePath),
            AttributeDataType::LongText => Textarea::make($statePath)->rows(4),
            AttributeDataType::Number => TextInput::make($statePath)
                ->inputMode('numeric')
                ->rule('regex:/^(?:0|-?[1-9]\d*)$/'),
            AttributeDataType::Decimal => TextInput::make($statePath)
                ->inputMode('decimal')
                ->rule('regex:/^-?\d+(?:\.\d+)?$/'),
            AttributeDataType::Boolean => Toggle::make($statePath),
            AttributeDataType::Date => DatePicker::make($statePath),
            AttributeDataType::Select => Select::make($statePath)
                ->options($this->optionLabels($definition))
                ->searchable(),
            AttributeDataType::MultiSelect => Select::make($statePath)
                ->options($this->optionLabels($definition))
                ->multiple()
                ->searchable(),
            AttributeDataType::Url => TextInput::make($statePath)->url(),
            default => throw new RuntimeException('Unsupported dynamic Product field type.'),
        };

        $component->label($label);
        if (filled($helper)) {
            $component->helperText($helper);
        }

        return $component;
    }

    /** @return array<string, string> */
    private function optionLabels(FieldDefinition $definition): array
    {
        $locale = app()->getLocale();
        $options = $definition->validation_rules['options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(fn ($option): bool => is_array($option) && is_string($option['code'] ?? null) && $option['code'] !== '')
            ->mapWithKeys(function (array $option) use ($locale): array {
                $code = (string) $option['code'];
                $labels = is_array($option['labels'] ?? null) ? $option['labels'] : [];

                return [
                    $code => (string) ($labels[$locale] ?? $labels['uk'] ?? $labels['en'] ?? $code),
                ];
            })
            ->all();
    }

    private function currentValue(FieldDefinition $definition, mixed $slot, string $locale): mixed
    {
        if ($slot === null) {
            return null;
        }

        if ($definition->is_localizable) {
            $map = is_array($slot->value_jsonb) ? $slot->value_jsonb : [];

            return $map[$locale] ?? null;
        }

        return match ($definition->data_type) {
            AttributeDataType::Text,
            AttributeDataType::LongText,
            AttributeDataType::Select,
            AttributeDataType::Date,
            AttributeDataType::Url => $slot->value_text,
            AttributeDataType::Number => $this->trimDecimal((string) $slot->value_num, true),
            AttributeDataType::Decimal => $this->trimDecimal((string) $slot->value_num, false),
            AttributeDataType::Boolean => match ((string) $slot->value_num) {
                '1.000000' => true,
                '0.000000' => false,
                default => null,
            },
            AttributeDataType::MultiSelect => is_array($slot->value_jsonb) ? $slot->value_jsonb : [],
            default => null,
        };
    }

    private function isEmptyValue(FieldDefinition $definition, mixed $value): bool
    {
        if ($definition->data_type === AttributeDataType::Boolean) {
            return $value === null;
        }

        if ($definition->data_type === AttributeDataType::MultiSelect) {
            return ! is_array($value) || $value === [];
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function trimDecimal(string $value, bool $integer): string
    {
        if ($integer) {
            return (string) (int) $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '-0' || $trimmed === '' ? '0' : $trimmed;
    }

    private function coordinateKey(FieldObjectType $type, int $targetId, string $bindingId): string
    {
        return $type->value.':'.$targetId.':'.$bindingId;
    }

    /** @return array<string, mixed> */
    private function decodeSnapshot(mixed $token): array
    {
        if (! is_string($token) || $token === '') {
            throw ProductWorkspaceFieldEditStaleException::snapshotMissing();
        }

        try {
            $snapshot = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ProductWorkspaceFieldEditStaleException::snapshotMissing();
        }

        if (! is_array($snapshot)) {
            throw ProductWorkspaceFieldEditStaleException::snapshotMissing();
        }

        return $snapshot;
    }

    private function assertSnapshotMatches(Product $product, array $projection, array $snapshot): void
    {
        $currentCoordinates = array_keys($projection['coordinates']);
        sort($currentCoordinates);
        $snapshotCoordinates = $snapshot['coordinates'] ?? [];
        if (is_array($snapshotCoordinates)) {
            sort($snapshotCoordinates);
        }

        if (($snapshot['workspace_id'] ?? null) !== (string) $product->workspace_id
            || (int) ($snapshot['product_id'] ?? 0) !== (int) $product->id
            || ($snapshot['product_type_id'] ?? null) !== (string) $projection['product_type']->id
            || (int) ($snapshot['structure_revision'] ?? -1) !== (int) $projection['product_type']->structure_revision
            || ($snapshot['locale'] ?? null) !== $projection['locale']
            || ! is_array($snapshotCoordinates)
            || $snapshotCoordinates !== $currentCoordinates
            || ! is_array($snapshot['expected'] ?? null)) {
            throw ProductWorkspaceFieldEditStaleException::structureChanged();
        }
    }
}
