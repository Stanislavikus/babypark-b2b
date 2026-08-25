<?php

namespace App\Services\Fields\Exceptions;

final class LocalizationContractViolationException extends FieldValueWriterException
{
    public static function localeRequiredForLocalizable(string $fieldDefinitionId): self
    {
        return new self(
            "Locale is required for writes to localizable field definition {$fieldDefinitionId}."
        );
    }

    public static function localeForbiddenForNonLocalizable(string $fieldDefinitionId): self
    {
        return new self(
            "Locale must not be provided for non-localizable field definition {$fieldDefinitionId}."
        );
    }

    public static function invalidLocale(string $locale): self
    {
        return new self("Locale '{$locale}' is not a syntactically valid BCP-47 / app-supported tag.");
    }

    public static function corruptLocalizedStorage(string $fieldDefinitionId): self
    {
        return new self(
            "Existing localized value row for field definition {$fieldDefinitionId} is corrupt (not a JSON object). Refusing to silently overwrite."
        );
    }
}
