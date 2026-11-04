<?php

namespace App\Support\Sync\Receive;

use App\Enums\FieldObjectType;
use App\Enums\ReceiveDiffState;
use App\Enums\ReceiveDomainRoute;

final readonly class ReceiveProposalEntry
{
    public function __construct(
        public string $fieldBindingId,
        public FieldObjectType $objectType,
        public ReceiveDomainRoute $domainRoute,
        public ReceiveDiffState $diffState,
        public bool $localValuePresent,
        public mixed $localCanonicalValue,
        public bool $remoteValuePresent,
        public mixed $remoteCanonicalValue,
        public bool $explicitClear,
        public ?string $blockedReasonCode = null,
    ) {}
}
