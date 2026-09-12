<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaPayload
{
    /** @var list<CanonicalSchemaOption> */
    private readonly array $options;

    private readonly bool $hasOptions;

    private function __construct(
        bool $hasOptions,
        #[\SensitiveParameter] array $options = [],
        private readonly ?string $providerMetadataVersion = null,
        #[\SensitiveParameter] private readonly ?object $providerMetadata = null,
    ) {
        $this->hasOptions = $hasOptions;
        $this->options = $options;
    }

    public static function empty(): self
    {
        return new self(false);
    }

    public static function withOptions(
        #[\SensitiveParameter] mixed $options,
        string $path = 'normalized_payload.options',
    ): self {
        if (! is_array($options) || ! array_is_list($options)) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::MalformedList,
                $path,
            );
        }

        foreach ($options as $option) {
            if (! $option instanceof CanonicalSchemaOption) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::InvalidType,
                    $path,
                );
            }
        }

        /** @var list<CanonicalSchemaOption> $options */
        $seen = [];

        foreach ($options as $option) {
            if (isset($seen[$option->value()])) {
                throw ConnectorDiscoverySchemaValidationException::at(
                    ConnectorDiscoverySchemaValidationReason::DuplicateOptionValue,
                    $path,
                );
            }

            $seen[$option->value()] = true;
        }

        usort(
            $options,
            static fn (CanonicalSchemaOption $left, CanonicalSchemaOption $right): int => strcmp(
                $left->value(),
                $right->value(),
            ),
        );

        return new self(true, $options);
    }

    /** @param list<CanonicalSchemaOption>|null $options */
    public static function withProviderMetadata(
        string $version,
        #[\SensitiveParameter] object $metadata,
        #[\SensitiveParameter] ?array $options = null,
    ): self {
        if ($version === '' || ! mb_check_encoding($version, 'UTF-8')) {
            throw ConnectorDiscoverySchemaValidationException::at(
                ConnectorDiscoverySchemaValidationReason::InvalidType,
                'normalized_payload',
            );
        }

        if ($options !== null) {
            $withOptions = self::withOptions($options);

            return new self(true, $withOptions->options, $version, $metadata);
        }

        return new self(false, [], $version, $metadata);
    }

    public function toCanonicalObject(): object
    {
        $payload = new \stdClass;
        $optionObjects = [];

        foreach ($this->options as $option) {
            $optionObject = new \stdClass;

            if ($option->label() !== null) {
                $optionObject->label = $option->label();
            }

            $optionObject->value = $option->value();
            $optionObjects[] = $optionObject;
        }

        if ($this->hasOptions) {
            $payload->options = $optionObjects;
        }

        if ($this->providerMetadataVersion !== null && $this->providerMetadata !== null) {
            $payload->provider_metadata = $this->providerMetadata;
            $payload->provider_metadata_version = $this->providerMetadataVersion;
        }

        return $payload;
    }
}
