<?php

namespace App\Support\Connectors\AdobePaaS;

final class AdobeProductRoutingFieldPolicy
{
    /** @var list<string> */
    private const ROUTING_EXTERNAL_KEYS = [
        'url_key',
        'url_path',
    ];

    public static function isRoutingExternalKey(?string $externalFieldKey): bool
    {
        return is_string($externalFieldKey)
            && in_array($externalFieldKey, self::ROUTING_EXTERNAL_KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $projectedValues
     * @return array<string, mixed>
     */
    public static function withoutRoutingValues(array $projectedValues): array
    {
        return array_filter(
            $projectedValues,
            static fn (mixed $entry): bool => ! (
                is_array($entry)
                && self::isRoutingExternalKey($entry['external_field_key'] ?? null)
            ),
        );
    }
}
