<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\ColumnFieldNotAllowlistedException;
use App\Exceptions\Catalog\InvalidColumnFieldValueException;

final class GovernedProductVariantColumnValuePolicy
{
    private const NAME_MAX_LENGTH = 255;

    private const DESCRIPTION_MAX_BYTES = 65535;

    public function normalizeSetValue(string $fieldCode, mixed $value): string
    {
        if ($value === null) {
            throw InvalidColumnFieldValueException::nullSetPayload($fieldCode);
        }

        if (! is_string($value)) {
            throw InvalidColumnFieldValueException::nonStringPayload($fieldCode);
        }

        return match ($fieldCode) {
            'name' => $this->normalizeName($value),
            'description' => $this->normalizeDescription($value),
            default => throw ColumnFieldNotAllowlistedException::forBinding('<unknown>', $fieldCode, null),
        };
    }

    private function normalizeName(string $value): string
    {
        if ($value === '') {
            throw InvalidColumnFieldValueException::emptyName();
        }

        if (trim($value) === '') {
            throw InvalidColumnFieldValueException::whitespaceOnlyName();
        }

        if (mb_strlen($value) > self::NAME_MAX_LENGTH) {
            throw InvalidColumnFieldValueException::nameTooLong(self::NAME_MAX_LENGTH);
        }

        return $value;
    }

    private function normalizeDescription(string $value): string
    {
        if (strlen($value) > self::DESCRIPTION_MAX_BYTES) {
            throw InvalidColumnFieldValueException::descriptionTooLong(self::DESCRIPTION_MAX_BYTES);
        }

        return $value;
    }
}
