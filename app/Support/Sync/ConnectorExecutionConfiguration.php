<?php

namespace App\Support\Sync;

use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;

final readonly class ConnectorExecutionConfiguration
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(private array $payload) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): self
    {
        if ($payload === null || $payload === []) {
            return self::empty();
        }

        if (! self::isAssociativeObjectPayload($payload)) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'Connector execution configuration must be a JSON object.',
            );
        }

        return new self(self::canonicalizePayload($payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRevisionArray(): array
    {
        return $this->payload;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function isAssociativeObjectPayload(array $payload): bool
    {
        if ($payload === []) {
            return true;
        }

        return array_is_list($payload) === false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function canonicalizePayload(array $payload): array
    {
        ksort($payload, SORT_STRING);

        $canonical = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                    'Connector execution configuration keys must be strings.',
                );
            }

            $canonical[$key] = self::canonicalizeValue($value);
        }

        return $canonical;
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    static fn (mixed $item): mixed => self::canonicalizeValue($item),
                    $value,
                );
            }

            if (! self::isAssociativeObjectPayload($value)) {
                throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                    'Connector execution configuration nested arrays must be JSON objects or lists.',
                );
            }

            return self::canonicalizePayload($value);
        }

        if (is_bool($value) || is_int($value) || is_string($value) || $value === null) {
            return $value;
        }

        throw ConnectorExecutionConfigurationValidationException::invalidPayload(
            'Connector execution configuration values must be JSON-safe primitives, objects, or lists.',
        );
    }
}
