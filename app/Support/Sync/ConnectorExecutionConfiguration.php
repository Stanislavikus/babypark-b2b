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

        if (! is_array($payload)) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'Connector execution configuration must be a JSON object.',
            );
        }

        $canonical = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                    'Connector execution configuration keys must be strings.',
                );
            }

            if ($key === 'attribute_set_id') {
                if (! is_int($value)) {
                    throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                        'attribute_set_id must be an integer.',
                    );
                }

                if ($value < 1) {
                    throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                        'attribute_set_id must be a positive integer.',
                    );
                }

                $canonical[$key] = $value;

                continue;
            }

            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                "Unsupported connector execution configuration key: {$key}.",
            );
        }

        ksort($canonical, SORT_STRING);

        return new self($canonical);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function attributeSetId(): ?int
    {
        $value = $this->payload['attribute_set_id'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function withAttributeSetId(int $attributeSetId): self
    {
        return self::fromPayload([
            ...$this->payload,
            'attribute_set_id' => $attributeSetId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toRevisionArray(): array
    {
        return $this->payload;
    }
}
