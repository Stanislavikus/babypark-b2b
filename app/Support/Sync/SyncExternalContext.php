<?php

namespace App\Support\Sync;

use App\Support\Sync\Exceptions\SyncExternalContextValidationException;

final readonly class SyncExternalContext
{
    private const DEFAULT_PAYLOAD = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(private array $payload) {}

    public static function default(): self
    {
        return new self(self::DEFAULT_PAYLOAD);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        if (! self::isAssociativeObjectPayload($payload)) {
            throw SyncExternalContextValidationException::invalidPayload(
                'External context payload must be a JSON object.',
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

    public function isDefault(): bool
    {
        return $this->payload === self::DEFAULT_PAYLOAD;
    }

    public function uniquenessKey(): string
    {
        return hash('sha256', self::PREFIX."\n".$this->encodeCanonicalJson($this->payload));
    }

    public function equals(self $other): bool
    {
        return $this->uniquenessKey() === $other->uniquenessKey();
    }

    private const PREFIX = 'babypark.sync-external-context.v1';

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
                throw SyncExternalContextValidationException::invalidPayload(
                    'External context object keys must be strings.',
                );
            }

            $canonical[$key] = self::canonicalizeValue($value);
        }

        return $canonical;
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! self::isAssociativeObjectPayload($value)) {
                throw SyncExternalContextValidationException::invalidPayload(
                    'External context arrays must be JSON objects.',
                );
            }

            return self::canonicalizePayload($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        throw SyncExternalContextValidationException::invalidPayload(
            'External context values must be JSON primitives or nested objects.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeCanonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            throw SyncExternalContextValidationException::invalidPayload(
                'External context payload could not be encoded as canonical JSON.',
            );
        }
    }
}
