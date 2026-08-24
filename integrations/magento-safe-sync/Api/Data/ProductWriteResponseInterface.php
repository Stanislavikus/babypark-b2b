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

    /**
     * Gets the bridge-authored applied-state classification.
     *
     * @return string Bridge-authored applied-state classification.
     */
    public function getAppliedState(): string;

    /**
     * Sets the bridge-authored applied-state classification.
     *
     * @param  string  $appliedState  Bridge-authored applied-state classification.
     * @return $this
     */
    public function setAppliedState(string $appliedState): self;

    /**
     * Gets the bounded Safe Sync reason code.
     *
     * @return string Bounded Safe Sync reason code.
     */
    public function getReasonCode(): string;

    /**
     * Sets the bounded Safe Sync reason code.
     *
     * @param  string  $reasonCode  Bounded Safe Sync reason code.
     * @return $this
     */
    public function setReasonCode(string $reasonCode): self;

    /**
     * Gets the logical product identity bound to the response.
     *
     * @return int Logical product identity bound to the response.
     */
    public function getLogicalEntityId(): int;

    /**
     * Sets the logical product identity bound to the response.
     *
     * @param  int  $logicalEntityId  Logical product identity bound to the response.
     * @return $this
     */
    public function setLogicalEntityId(int $logicalEntityId): self;

    /**
     * Gets the response SKU evidence.
     *
     * @return string Response SKU evidence.
     */
    public function getSku(): string;

    /**
     * Sets the response SKU evidence.
     *
     * @param  string  $sku  Response SKU evidence.
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * Gets whether the exact postcondition was verified.
     *
     * @return bool Whether the exact postcondition was verified.
     */
    public function getPostconditionVerified(): bool;

    /**
     * Sets whether the exact postcondition was verified.
     *
     * @param  bool  $postconditionVerified  Whether the exact postcondition was verified.
     * @return $this
     */
    public function setPostconditionVerified(bool $postconditionVerified): self;

    /**
     * Gets the consequential write-attempt count.
     *
     * @return int Consequential write-attempt count.
     */
    public function getConsequentialWriteAttempts(): int;

    /**
     * Sets the consequential write-attempt count.
     *
     * @param  int  $consequentialWriteAttempts  Consequential write-attempt count.
     * @return $this
     */
    public function setConsequentialWriteAttempts(int $consequentialWriteAttempts): self;

    /**
     * Gets bounded operational warning codes.
     *
     * @return string[] Bounded operational warning codes.
     */
    public function getWarningCodes(): array;

    /**
     * Sets bounded operational warning codes.
     *
     * @param  string[]  $warningCodes  Bounded operational warning codes.
     * @return $this
     */
    public function setWarningCodes(array $warningCodes): self;
}
