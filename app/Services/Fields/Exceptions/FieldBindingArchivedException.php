<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\AttributeStatus;

final class FieldBindingArchivedException extends FieldValueWriterException
{
    public static function forId(string $fieldBindingId, AttributeStatus $status): self
    {
        return new self("Field binding {$fieldBindingId} is {$status->value}.");
    }
}
