<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;

final class CanonicalSchemaPayload
{
    /** @var list<CanonicalSchemaOption> */
    private readonly array $options;

    private readonly bool $hasOptions;

    private function __construct(bool $hasOptions, #[\SensitiveParameter] array $options = [])
    {
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

    public function toCanonicalObject(): object
    {
        if (! $this->hasOptions) {
            return (object) [];
        }

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

        $payload->options = $optionObjects;

        return $payload;
    }
}
