<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

final class ColumnFieldNotAllowlistedException extends RuntimeException
{
    public static function forBinding(string $fieldBindingId, string $fieldCode, ?string $storagePath): self
    {
        $path = $storagePath ?? '<null>';

        return new self(
            "Field binding {$fieldBindingId} ({$fieldCode} @ {$path}) is not admitted to the governed Product/Variant column mutation allowlist."
        );
    }
}
