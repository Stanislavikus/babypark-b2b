<?php

namespace Tests\Support\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Services\Sync\FieldDefinitionInternalOptionValidator;
use App\Support\Sync\FieldOptionMappingOptionValidator;

final class CountingTestFieldOptionMappingOptionValidator implements FieldOptionMappingOptionValidator
{
    public int $validateCallCount = 0;

    public function __construct(
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
    ) {}

    public function validate(
        ConnectorAccount $account,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void {
        $this->validateCallCount++;

        $this->internalOptionValidator->validate($mapping, $internalOptionKey);
    }
}
