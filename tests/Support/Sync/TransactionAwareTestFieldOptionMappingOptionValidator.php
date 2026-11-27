<?php

namespace Tests\Support\Sync;

use App\Models\ConnectorAccount;
use App\Models\FieldMapping;
use App\Services\Sync\FieldDefinitionInternalOptionValidator;
use App\Support\Sync\FieldOptionMappingOptionValidator;
use Illuminate\Support\Facades\DB;

final class TransactionAwareTestFieldOptionMappingOptionValidator implements FieldOptionMappingOptionValidator
{
    public bool $validatedInsideTransaction = false;

    public ?int $transactionLevelAtValidate = null;

    public function __construct(
        private readonly FieldDefinitionInternalOptionValidator $internalOptionValidator,
    ) {}

    public function validate(
        ConnectorAccount $account,
        FieldMapping $mapping,
        string $internalOptionKey,
        string $externalOptionValue,
    ): void {
        $this->transactionLevelAtValidate = DB::transactionLevel();

        if (DB::transactionLevel() > 0) {
            $this->validatedInsideTransaction = true;
        }

        $this->internalOptionValidator->validate($mapping, $internalOptionKey);
    }
}
