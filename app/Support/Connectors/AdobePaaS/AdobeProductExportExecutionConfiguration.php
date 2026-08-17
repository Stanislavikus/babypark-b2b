<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Support\Sync\Exceptions\ConnectorExecutionConfigurationValidationException;

final readonly class AdobeProductExportExecutionConfiguration
{
    public function __construct(public int $attributeSetId) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $attributeSetId = $payload['attribute_set_id'] ?? null;

        if (! is_int($attributeSetId)) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'attribute_set_id must be an integer.',
            );
        }

        if ($attributeSetId < 1) {
            throw ConnectorExecutionConfigurationValidationException::invalidPayload(
                'attribute_set_id must be a positive integer.',
            );
        }

        return new self($attributeSetId);
    }

    /**
     * @return array{attribute_set_id: int}
     */
    public function toPayload(): array
    {
        return [
            'attribute_set_id' => $this->attributeSetId,
        ];
    }
}
