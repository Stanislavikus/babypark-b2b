<?php

namespace App\Support\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;

interface FieldOptionMappingOptionValidator
{
    public function validate(
        ConnectorAccount $account,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void;
}
