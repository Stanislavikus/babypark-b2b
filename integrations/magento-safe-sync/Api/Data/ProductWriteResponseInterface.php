<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductWriteResponseInterface
{
    public const APPLIED_STATE = 'applied_state';

    public const REASON_CODE = 'reason_code';

    public const LOGICAL_ENTITY_ID = 'logical_entity_id';

    public const SKU = 'sku';

    public const POSTCONDITION_VERIFIED = 'postcondition_verified';

    public const CONSEQUENTIAL_WRITE_ATTEMPTS = 'consequential_write_attempts';

    public const WARNING_CODES = 'warning_codes';

    public function getAppliedState(): string;

    public function setAppliedState(string $appliedState): self;

    public function getReasonCode(): string;

    public function setReasonCode(string $reasonCode): self;

    public function getLogicalEntityId(): int;

    public function setLogicalEntityId(int $logicalEntityId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getPostconditionVerified(): bool;

    public function setPostconditionVerified(bool $postconditionVerified): self;

    public function getConsequentialWriteAttempts(): int;

    public function setConsequentialWriteAttempts(int $consequentialWriteAttempts): self;

    /**
     * @return list<string>
     */
    public function getWarningCodes(): array;

    /**
     * @param  list<string>  $warningCodes
     */
    public function setWarningCodes(array $warningCodes): self;
}
