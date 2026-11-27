<?php

namespace App\Services\Fields\Exceptions;

final class FieldBindingWorkspaceMismatchException extends FieldValueWriterException
{
    public static function forId(string $fieldBindingId, string $expected, ?string $actual): self
    {
        $actualLabel = $actual ?? '<global>';

        return new self(
            "Field binding {$fieldBindingId} belongs to workspace {$actualLabel}, not {$expected}."
        );
    }
}
