<?php

namespace Tests\Support\Connectors\Fixtures;

final class MagentoPilotAttributesDiscoveryFixture
{
    private const FIXTURE_FILENAME = 'magento_pilot_attributes_discovery_real.json';

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
        return self::freshItems(self::canonicalPayload()->items);
    }

    /**
     * @return array{items: list<\stdClass>, total_count: int}
     */
    public static function singlePageResponse(): array
    {
        $payload = self::canonicalPayload();

        return [
            'items' => self::freshItems($payload->items),
            'total_count' => $payload->total_count,
        ];
    }

    /**
     * @return array{pages: list<array{items: list<\stdClass>, total_count: int}>, total_count: int}
     */
    public static function paginatedResponse(int $firstPageSize = 60): array
    {
        $payload = self::canonicalPayload();
        $items = self::freshItems($payload->items);
        $firstPage = array_slice($items, 0, $firstPageSize);
        $secondPage = array_slice($items, $firstPageSize);

        return [
            'pages' => [
                [
                    'items' => $firstPage,
                    'total_count' => $payload->total_count,
                ],
                [
                    'items' => $secondPage,
                    'total_count' => $payload->total_count,
                ],
            ],
            'total_count' => $payload->total_count,
        ];
    }

    private static function fixturePath(): string
    {
        return __DIR__.'/'.self::FIXTURE_FILENAME;
    }

    private static function rawJson(): string
    {
        static $json = null;

        if ($json !== null) {
            return $json;
        }

        $path = self::fixturePath();

        if (! is_readable($path)) {
            throw new \RuntimeException(sprintf(
                'Magento pilot attributes discovery fixture is unreadable at [%s].',
                $path,
            ));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf(
                'Magento pilot attributes discovery fixture could not be read from [%s].',
                $path,
            ));
        }

        $json = $contents;

        return $json;
    }

    private static function canonicalPayload(): \stdClass
    {
        try {
            $payload = json_decode(self::rawJson(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Magento pilot attributes discovery fixture contains malformed JSON.',
                previous: $exception,
            );
        }

        if (! $payload instanceof \stdClass) {
            throw new \RuntimeException('Magento pilot attributes discovery fixture root must be a JSON object.');
        }

        if (! property_exists($payload, 'total_count') || ! is_int($payload->total_count)) {
            throw new \RuntimeException('Magento pilot attributes discovery fixture must contain integer total_count.');
        }

        if ($payload->total_count !== self::RECEIVED_COUNT) {
            throw new \RuntimeException(sprintf(
                'Magento pilot attributes discovery fixture total_count must be %d.',
                self::RECEIVED_COUNT,
            ));
        }

        if (! property_exists($payload, 'items') || ! is_array($payload->items) || ! array_is_list($payload->items)) {
            throw new \RuntimeException('Magento pilot attributes discovery fixture must contain a list of items.');
        }

        if (count($payload->items) !== self::RECEIVED_COUNT) {
            throw new \RuntimeException(sprintf(
                'Magento pilot attributes discovery fixture must contain exactly %d items.',
                self::RECEIVED_COUNT,
            ));
        }

        return $payload;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<\stdClass>
     */
    private static function freshItems(array $items): array
    {
        return array_map(
            static fn (mixed $item): \stdClass => json_decode(
                json_encode($item, JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR,
            ),
            $items,
        );
    }
}
