<?php

namespace Tests\Support\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Services\Sync\FieldDefinitionInternalOptionValidator;
use App\Support\Sync\FieldOptionMappingOptionValidator;

final class TestFieldOptionMappingOptionValidator implements FieldOptionMappingOptionValidator
{
    public function __construct(
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
    ) {}

    public function validate(
        ConnectorAccount $account,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void {
        $this->internalOptionValidator->validate($mapping, $internalOptionKey);
    }
}
