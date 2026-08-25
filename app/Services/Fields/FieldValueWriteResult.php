<?php

namespace App\Services\Fields;

/**
 * Result of a GovernedDynamicFieldValueWriter set/clear operation.
 *
 * The status contract is small and exhaustive; callers (Magento Receive,
 * CSV/Smart Import, Google Sheets, ERP/1C, product-card editing) MUST
 * use the explicit string constants instead of a raw boolean. The field
 * binding id is returned so callers can correlate without re-resolving.
 */
final class FieldValueWriteResult
{
    public const Created = 'created';

    public const Updated = 'updated';

    public const Deleted = 'deleted';

    public const NoOp = 'no_op';

    /**
     * @param  self::Created|self::Updated|self::Deleted|self::NoOp  $status
     */
    public function __construct(
        public readonly string $status,
        public readonly string $fieldBindingId,
    ) {}

    public function isMutation(): bool
    {
        return $this->status !== self::NoOp;
    }
}
