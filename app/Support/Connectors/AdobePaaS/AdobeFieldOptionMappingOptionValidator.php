<?php

namespace App\Support\Connectors\AdobePaaS;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Services\Sync\FieldDefinitionInternalOptionValidator;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\FieldOptionMappingOptionValidator;

final class AdobeFieldOptionMappingOptionValidator implements FieldOptionMappingOptionValidator
{
    public function __construct(
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
        private readonly AdobeProductExportMetadataReader $metadataReader,
    ) {}

    public function validate(
        ConnectorAccount $account,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void {
        $this->internalOptionValidator->validate($mapping, $internalOptionKey);

        $mapping->loadMissing('syncConfiguration');

        $configuration = $mapping->syncConfiguration;

        if ($configuration === null) {
            throw FieldMappingValidationException::mappingNotFound('', $mapping->id);
        }

        $exportConfiguration = AdobeProductExportExecutionConfiguration::fromPayload(
            $configuration->connectorExecutionConfiguration()->payload(),
        );

        $metadata = $this->metadataReader->read(
            $account->workspace_id,
            $account->id,
            $exportConfiguration->attributeSetId,
        );

        $externalFieldKey = $mapping->external_field_key;

        if (! is_string($externalFieldKey) || $externalFieldKey === '') {
            return;
        }

        if ($metadata->attributeByCode($externalFieldKey) === null) {
            throw FieldMappingValidationException::invalidExternalOptionValue(
                $externalOptionValue,
                $externalFieldKey,
            );
        }

        if (! $metadata->optionExists($externalFieldKey, $externalOptionValue)) {
            throw FieldMappingValidationException::invalidExternalOptionValue(
                $externalOptionValue,
                $externalFieldKey,
            );
        }
    }
}
