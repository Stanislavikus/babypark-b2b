<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldMappingProjectionInvariantException extends RuntimeException
{
    public static function invalidPersistedMapping(): self
    {
        return new self(
            'Field mapping projection invariant violated: persisted mapping references an ineligible field target.',
        );
    }
}
