<?php

namespace App\Support\Connectors\AdobePaaS\Exceptions;

use RuntimeException;

final class AdobeStoreConfigReadException extends RuntimeException
{
    public static function transportFailure(): self
    {
        return new self('Adobe store configuration could not be retrieved.');
    }

    public static function invalidResponse(): self
    {
        return new self('Adobe store configuration response was not valid JSON.');
    }

    public static function unexpectedShape(): self
    {
        return new self('Adobe store configuration response did not contain the expected store row.');
    }

    public static function missingBaseCurrency(): self
    {
        return new self('Adobe store configuration did not include base_currency_code.');
    }
}
