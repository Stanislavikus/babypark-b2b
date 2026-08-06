<?php

namespace Tests\Support\Connectors\Fixtures;

final class MagentoPilotAttributesDiscoveryFixture
{
    public const RECEIVED_COUNT = 106;

    public const NORMALIZED_COUNT = 102;

    /**
     * @var list<string>
     */
    public const SERVICE_ONLY_ATTRIBUTE_CODES = [
        'links_purchased_separately',
        'samples_title',
        'links_title',
        'links_exist',
    ];

    /**
     * @var list<string>
     */
    public const REPRESENTATIVE_INVISIBLE_NORMALIZED_CODES = [
        'created_at',
        'minimal_price',
        'url_path',
    ];

    /**
     * @return list<\stdClass>
     */
    public static function allItems(): array
    {
        $items = array_merge(
            self::merchantFacingItems(),
            self::serviceOnlyItems(),
        );

        if (count($items) !== self::RECEIVED_COUNT) {
            throw new \LogicException('Fixture item count drifted from the pilot payload contract.');
        }

        return $items;
    }

    /**
     * @return array{items: list<\stdClass>, total_count: int}
     */
    public static function singlePageResponse(): array
    {
        return [
            'items' => self::allItems(),
            'total_count' => self::RECEIVED_COUNT,
        ];
    }

    /**
     * @return array{pages: list<array{items: list<\stdClass>, total_count: int}>, total_count: int}
     */
    public static function paginatedResponse(int $firstPageSize = 60): array
    {
        $items = self::allItems();
        $firstPage = array_slice($items, 0, $firstPageSize);
        $secondPage = array_slice($items, $firstPageSize);

        return [
            'pages' => [
                [
                    'items' => $firstPage,
                    'total_count' => self::RECEIVED_COUNT,
                ],
                [
                    'items' => $secondPage,
                    'total_count' => self::RECEIVED_COUNT,
                ],
            ],
            'total_count' => self::RECEIVED_COUNT,
        ];
    }

    /**
     * @return list<\stdClass>
     */
    private static function merchantFacingItems(): array
    {
        $frontendInputs = [
            'text', 'textarea', 'texteditor', 'date', 'datetime', 'boolean',
            'select', 'multiselect', 'price', 'media_image', 'gallery', 'weight',
        ];

        $scopes = ['global', 'website', 'store'];
        $items = [];

        $items[] = self::attribute(
            'created_at',
            'datetime',
            'global',
            isVisible: false,
            label: 'Created At',
        );
        $items[] = self::attribute(
            'minimal_price',
            'price',
            'global',
            isVisible: false,
            label: 'Minimal Price',
        );
        $items[] = self::attribute(
            'url_path',
            'text',
            'store',
            isVisible: false,
            label: 'URL Path',
        );

        for ($index = 0; $index < 99; $index++) {
            $code = sprintf('fixture_attr_%03d', $index + 1);
            $frontendInput = $frontendInputs[$index % count($frontendInputs)];
            $scope = $scopes[$index % count($scopes)];
            $options = in_array($frontendInput, ['select', 'multiselect'], true)
                ? [
                    ['label' => 'Option A', 'value' => 'a'],
                    ['label' => 'Option B', 'value' => 'b'],
                ]
                : null;

            $items[] = self::attribute(
                $code,
                $frontendInput,
                $scope,
                isVisible: $index % 7 !== 0,
                label: 'Fixture Attribute '.$index,
                options: $options,
                position: $index,
            );
        }

        if (count($items) !== self::NORMALIZED_COUNT) {
            throw new \LogicException('Fixture normalized item count drifted from the pilot payload contract.');
        }

        return $items;
    }

    /**
     * @return list<\stdClass>
     */
    private static function serviceOnlyItems(): array
    {
        $items = [];

        foreach (self::SERVICE_ONLY_ATTRIBUTE_CODES as $attributeCode) {
            $items[] = self::serviceOnlyAttribute($attributeCode);
        }

        return $items;
    }

    /**
     * @param  ?list<array{label: string, value: string}>  $options
     */
    private static function attribute(
        string $attributeCode,
        string $frontendInput,
        string $scope,
        bool $isVisible = true,
        ?string $label = null,
        ?array $options = null,
        ?int $position = null,
    ): \stdClass {
        $payload = [
            'attribute_code' => $attributeCode,
            'frontend_input' => $frontendInput,
            'scope' => $scope,
            'is_user_defined' => false,
            'is_visible' => $isVisible,
            'default_frontend_label' => $label,
            'is_required' => $attributeCode === 'fixture_attr_001',
            'backend_type' => 'varchar',
            'apply_to' => [],
        ];

        if ($position !== null) {
            $payload['position'] = $position;
        }

        if ($options !== null) {
            $payload['options'] = $options;
        }

        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function serviceOnlyAttribute(string $attributeCode): \stdClass
    {
        $payload = [
            'attribute_code' => $attributeCode,
            'frontend_input' => null,
            'scope' => 'global',
            'is_user_defined' => false,
            'is_visible' => false,
            'default_frontend_label' => null,
            'backend_type' => in_array($attributeCode, ['links_purchased_separately', 'links_exist'], true) ? 'int' : 'varchar',
            'apply_to' => ['downloadable'],
        ];

        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
