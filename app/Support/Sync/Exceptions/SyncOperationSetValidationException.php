<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class SyncOperationSetValidationException extends RuntimeException
{
    public static function emptySet(): self
    {
        return new self('At least one semantic operation must be enabled.');
    }

    public static function malformedList(): self
    {
        return new self('Enabled operations must be provided as a list.');
    }

    public static function invalidType(int $index): self
    {
        return new self(sprintf('Enabled operation at index [%d] must be a SyncSemanticOperation.', $index));
    }
}
