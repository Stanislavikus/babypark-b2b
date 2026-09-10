<?php

namespace App\Services\Sync;

use Illuminate\Database\QueryException;

final class FieldOptionMappingConstraintViolationClassifier
{
    public function isInternalOptionConflict(QueryException $exception): bool
    {
        return $this->matchesConstraint($exception, 'fom_mapping_internal_option_unique');
    }

    private function matchesConstraint(QueryException $exception, string $constraintName): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = $exception->getMessage();
        $driverCode = $exception->errorInfo[1] ?? null;

        if ($this->isMysqlConflict($sqlState, $driverCode, $message, $constraintName)) {
            return true;
        }

        return $this->isSqliteConflict($sqlState, $message, $constraintName);
    }

    private function isMysqlConflict(
        string $sqlState,
        mixed $driverCode,
        string $message,
        string $constraintName,
    ): bool {
        if ($sqlState !== '23000') {
            return false;
        }

        if ($driverCode !== 1062) {
            return false;
        }

        return str_contains($message, $constraintName);
    }

    private function isSqliteConflict(string $sqlState, string $message, string $constraintName): bool
    {
        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($message, $constraintName)
            || str_contains($message, 'field_option_mappings.field_mapping_id, field_option_mappings.internal_option_key');
    }
}
