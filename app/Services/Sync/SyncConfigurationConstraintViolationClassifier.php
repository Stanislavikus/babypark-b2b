<?php

namespace App\Services\Sync;

use Illuminate\Database\QueryException;

final class SyncConfigurationConstraintViolationClassifier
{
    public function isIdentityUniquenessConflict(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = $exception->getMessage();
        $driverCode = $exception->errorInfo[1] ?? null;

        if ($this->isMysqlIdentityConflict($sqlState, $driverCode, $message)) {
            return true;
        }

        return $this->isSqliteIdentityConflict($sqlState, $message);
    }

    private function isMysqlIdentityConflict(string $sqlState, mixed $driverCode, string $message): bool
    {
        if ($sqlState !== '23000') {
            return false;
        }

        if ($driverCode !== 1062) {
            return false;
        }

        return str_contains($message, 'sc_account_domain_context_unique');
    }

    private function isSqliteIdentityConflict(string $sqlState, string $message): bool
    {
        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($message, 'sync_configurations.connector_account_id, sync_configurations.data_domain, sync_configurations.external_context_key');
    }
}
