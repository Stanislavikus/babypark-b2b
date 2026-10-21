<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use App\Enums\FieldObjectType;

final readonly class AdobeProductDynamicSelectReceiveState
{
    public function __construct(
        public string $fieldMappingId,
        public string $fieldBindingId,
        public string $externalFieldKey,
        public FieldObjectType $objectType,
        public bool $localValuePresent,
        public ?string $localCanonicalValue,
        public bool $remoteValuePresent,
        public ?string $remoteCanonicalValue,
        public bool $isSupported,
        public ?string $blockedReasonCode,
    ) {}
}
