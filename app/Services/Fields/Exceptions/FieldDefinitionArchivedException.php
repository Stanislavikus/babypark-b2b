<?php

namespace App\Services\Fields\Exceptions;

use App\Enums\AttributeStatus;

final class FieldDefinitionArchivedException extends FieldValueWriterException
{
    public static function forId(string $fieldDefinitionId, AttributeStatus $status): self
    {
        return new self("Field definition {$fieldDefinitionId} is {$status->value}.");
    }
}
