<?php

namespace App\Services\Catalog;

final class ColumnMutationResult
{
    public const Updated = 'updated';

    public const NoOp = 'no_op';

    /**
     * @param  self::Updated|self::NoOp  $status
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
