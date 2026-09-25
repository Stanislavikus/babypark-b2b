<?php

namespace Tests\Feature;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use Database\Seeders\FieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDefinitionSeederFoundationSeedV5Test extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $v5Codes = [
        'condition',
        'short_description',
        'material',
        'country_of_origin',
        'manufacturer',
        'model',
        'compatibility',
        'battery_type',
    ];

    /** @var list<string> */
    private array $excludedCodes = [
        'age_group',
        'gender',
        'price',
        'sale_price',
        'cost_price',
        'availability',
        'image',
    ];

    /** @var array<string, int> */
    private array $expectedSortOrders = [
        'condition' => 120,
        'short_description' => 130,
        'material' => 140,
        'country_of_origin' => 150,
        'manufacturer' => 160,
        'model' => 170,
        'compatibility' => 180,
        'battery_type' => 190,
    ];

    public function test_foundation_seed_v5_branch_a_idempotent(): void
    {
        $beforeCounts = $this->captureCounts();

        foreach ($this->excludedCodes as $code) {
            $this->assertSame(
                0,
                FieldDefinition::withoutWorkspaceScope()->where('code', $code)->count(),
                "Excluded code '{$code}' must be absent before seeding"
            );
        }

        $this->seed(FieldDefinitionSeeder::class);

        $afterFirst = $this->captureCounts();
        $this->assertSame(8, $this->countV5Definitions());
        $this->assertSame(8, $this->countV5Bindings());
        $this->assertGreaterThan($beforeCounts['definitions'], $afterFirst['definitions']);
        $this->assertGreaterThan($beforeCounts['bindings'], $afterFirst['bindings']);

        $colorSnapshot = $this->snapshotField('color');
        $sizeSnapshot = $this->snapshotField('size');

        $this->seed(FieldDefinitionSeeder::class);

        $afterSecond = $this->captureCounts();
        $this->assertSame($afterFirst['definitions'], $afterSecond['definitions']);
        $this->assertSame($afterFirst['bindings'], $afterSecond['bindings']);
        $this->assertSame(8, $this->countV5Definitions());
        $this->assertSame(8, $this->countV5Bindings());
        $this->assertSame($colorSnapshot, $this->snapshotField('color'));
        $this->assertSame($sizeSnapshot, $this->snapshotField('size'));

        foreach ($this->expectedSortOrders as $code => $sortOrder) {
            $this->assertBindingSortOrder($code, $sortOrder);
        }
    }

    public function test_foundation_seed_v5_field_contracts(): void
    {
        $this->seed(FieldDefinitionSeeder::class);

        $this->assertFieldContract('condition', [
            'data_type' => AttributeDataType::Select,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::ProductVariant,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'basic_information',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Condition', 'uk' => 'Стан', 'ru' => 'Состояние'],
            'option_codes' => ['new', 'used', 'refurbished'],
        ]);

        $this->assertFieldContract('short_description', [
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'descriptions',
            'is_localizable' => true,
            'localized_labels' => ['en' => 'Short Description', 'uk' => 'Короткий опис', 'ru' => 'Краткое описание'],
        ]);

        $this->assertFieldContract('material', [
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'characteristics',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Material', 'uk' => 'Матеріал', 'ru' => 'Материал'],
        ]);

        $this->assertFieldContract('country_of_origin', [
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'characteristics',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Country of Origin', 'uk' => 'Країна походження', 'ru' => 'Страна происхождения'],
        ]);

        $this->assertFieldContract('manufacturer', [
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'basic_information',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Manufacturer', 'uk' => 'Виробник', 'ru' => 'Производитель'],
        ]);

        $this->assertFieldContract('model', [
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'basic_information',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Model', 'uk' => 'Модель', 'ru' => 'Модель'],
        ]);

        $this->assertFieldContract('compatibility', [
            'data_type' => AttributeDataType::LongText,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'characteristics',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Compatibility', 'uk' => 'Сумісність', 'ru' => 'Совместимость'],
        ]);

        $this->assertFieldContract('battery_type', [
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'object_type' => FieldObjectType::Product,
            'storage_type' => AttributeStorageType::Dynamic,
            'field_group' => 'characteristics',
            'is_localizable' => false,
            'localized_labels' => ['en' => 'Battery Type', 'uk' => 'Тип батареї', 'ru' => 'Тип батареи'],
        ]);

        foreach ($this->excludedCodes as $code) {
            $this->assertSame(
                0,
                FieldDefinition::withoutWorkspaceScope()->where('code', $code)->count(),
                "Excluded code '{$code}' must not be seeded"
            );
        }

        foreach ($this->v5Codes as $code) {
            $this->assertSame(
                1,
                FieldDefinition::withoutWorkspaceScope()->where('code', $code)->count(),
                "Expected exactly one definition for {$code}"
            );
            $this->assertSame(
                1,
                FieldBinding::withoutWorkspaceScope()
                    ->whereHas('fieldDefinition', fn ($q) => $q->where('code', $code))
                    ->count(),
                "Expected exactly one binding for {$code}"
            );
        }
    }

    /**
     * @return array{definitions: int, bindings: int}
     */
    private function captureCounts(): array
    {
        return [
            'definitions' => FieldDefinition::withoutWorkspaceScope()->count(),
            'bindings' => FieldBinding::withoutWorkspaceScope()->count(),
        ];
    }

    private function countV5Definitions(): int
    {
        return FieldDefinition::withoutWorkspaceScope()
            ->whereIn('code', $this->v5Codes)
            ->count();
    }

    private function countV5Bindings(): int
    {
        return FieldBinding::withoutWorkspaceScope()
            ->whereHas('fieldDefinition', fn ($q) => $q->whereIn('code', $this->v5Codes))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotField(string $code): array
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->where('code', $code)->firstOrFail();

        return [
            'data_type' => $definition->data_type->value,
            'scope' => $definition->scope->value,
            'localized_labels' => $definition->localized_labels,
            'validation_rules' => $definition->validation_rules,
            'bindings' => FieldBinding::withoutWorkspaceScope()
                ->where('field_definition_id', $definition->id)
                ->get()
                ->map(fn (FieldBinding $b) => [
                    'object_type' => $b->object_type->value,
                    'sort_order' => $b->sort_order,
                    'field_group' => $b->field_group,
                ])
                ->values()
                ->all(),
        ];
    }

    private function assertBindingSortOrder(string $code, int $expectedSortOrder): void
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->where('code', $code)->firstOrFail();
        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->firstOrFail();

        $this->assertSame($expectedSortOrder, $binding->sort_order);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertFieldContract(string $code, array $expected): void
    {
        $definition = FieldDefinition::withoutWorkspaceScope()->where('code', $code)->firstOrFail();

        $this->assertSame($expected['data_type'], $definition->data_type);
        $this->assertSame($expected['scope'], $definition->scope);
        $this->assertSame($expected['is_localizable'], $definition->is_localizable);
        $this->assertEquals($expected['localized_labels'], $definition->localized_labels);

        $binding = FieldBinding::withoutWorkspaceScope()
            ->where('field_definition_id', $definition->id)
            ->where('object_type', $expected['object_type'])
            ->firstOrFail();

        $this->assertSame($expected['storage_type'], $binding->storage_type);
        $this->assertSame($expected['field_group'], $binding->field_group);
        $this->assertSame($this->expectedSortOrders[$code], $binding->sort_order);

        if (isset($expected['option_codes'])) {
            $optionCodes = collect($definition->validation_rules['options'] ?? [])
                ->pluck('code')
                ->values()
                ->all();

            $this->assertSame($expected['option_codes'], $optionCodes);
        }
    }
}
