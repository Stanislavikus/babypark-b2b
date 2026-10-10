<?php

namespace App\Support\Sync\Receive;

use App\Enums\FieldObjectType;
use App\Enums\ReceiveDomainRoute;
use InvalidArgumentException;

final readonly class ReceiveFieldCandidate
{
    public function __construct(
        public string $fieldBindingId,
        public FieldObjectType $objectType,
        public ReceiveDomainRoute $domainRoute,
        public bool $localValuePresent,
        public mixed $localCanonicalValue,
        public bool $remoteValuePresent,
        public mixed $remoteCanonicalValue,
        public bool $explicitClear = false,
        public bool $isSupported = true,
        public ?string $blockedReasonCode = null,
    ) {
        if ($this->fieldBindingId === '') {
            throw new InvalidArgumentException('fieldBindingId must not be empty.');
        }

        if (! in_array($this->objectType, [FieldObjectType::Product, FieldObjectType::ProductVariant], true)) {
            throw new InvalidArgumentException('ReceiveFieldCandidate objectType must be Product or ProductVariant.');
        }

        if ($this->domainRoute === ReceiveDomainRoute::Unsupported && $this->isSupported) {
            throw new InvalidArgumentException('ReceiveFieldCandidate with Unsupported route must be unsupported or blocked.');
        }

        if ($this->localValuePresent === false && $this->localCanonicalValue !== null) {
            throw new InvalidArgumentException('localCanonicalValue must be null when localValuePresent is false.');
        }

        if ($this->remoteValuePresent === false && $this->remoteCanonicalValue !== null) {
            throw new InvalidArgumentException('remoteCanonicalValue must be null when remoteValuePresent is false.');
        }

        if ($this->isSupported) {
            if ($this->blockedReasonCode !== null) {
                throw new InvalidArgumentException('blockedReasonCode must be null when candidate is supported.');
            }
        } else {
            if ($this->blockedReasonCode === null || $this->blockedReasonCode === '') {
                throw new InvalidArgumentException('blockedReasonCode is required when candidate is unsupported or blocked.');
            }

            if (preg_match('/^[a-z0-9_]{1,64}$/', $this->blockedReasonCode) !== 1) {
                throw new InvalidArgumentException('blockedReasonCode must be a bounded lowercase underscore code.');
            }
        }
    }
}
