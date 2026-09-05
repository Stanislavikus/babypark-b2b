<?php

namespace App\Support\Sync;

use App\Enums\SyncSemanticOperation;
use App\Support\Sync\Exceptions\SyncOperationSetValidationException;

final readonly class SyncOperationSet
{
    /** @var list<SyncSemanticOperation> */
    private array $operations;

    /**
     * @param  list<SyncSemanticOperation>  $operations
     */
    private function __construct(array $operations)
    {
        $this->operations = $operations;
    }

    /**
     * @param  list<SyncSemanticOperation>  $operations
     */
    public static function fromOperations(array $operations): self
    {
        if ($operations === []) {
            throw SyncOperationSetValidationException::emptySet();
        }

        if (! array_is_list($operations)) {
            throw SyncOperationSetValidationException::malformedList();
        }

        $seen = [];
        $canonical = [];

        foreach ($operations as $index => $operation) {
            if (! $operation instanceof SyncSemanticOperation) {
                throw SyncOperationSetValidationException::invalidType($index);
            }

            $value = $operation->value;

            if (isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $canonical[] = $operation;
        }

        usort(
            $canonical,
            static fn (SyncSemanticOperation $left, SyncSemanticOperation $right): int => strcmp(
                $left->value,
                $right->value,
            ),
        );

        return new self($canonical);
    }

    /**
     * @return list<SyncSemanticOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(
            static fn (SyncSemanticOperation $operation): string => $operation->value,
            $this->operations,
        );
    }

    public function equals(self $other): bool
    {
        return $this->values() === $other->values();
    }

    public function contains(SyncSemanticOperation $operation): bool
    {
        foreach ($this->operations as $candidate) {
            if ($candidate === $operation) {
                return true;
            }
        }

        return false;
    }
}
