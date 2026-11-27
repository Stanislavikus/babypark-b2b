<?php

namespace App\Support\Sync\Exceptions;

use RuntimeException;

final class FieldOptionMappingStaleMutationException extends RuntimeException
{
    public static function configurationChanged(): self
    {
        return new self('Option mapping mutation rejected because configuration state changed.');
    }
}
