<?php

namespace App\Services\Connectors;

use Illuminate\Database\QueryException;

final class ConnectorAccountConstraintViolationClassifier
{
    public function isActiveNameUniquenessConflict(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = $exception->getMessage();
        $driverCode = $exception->errorInfo[1] ?? null;

        if ($this->isMysqlActiveNameConflict($sqlState, $driverCode, $message)) {
            return true;
        }

        return $this->isSqliteActiveNameConflict($sqlState, $message);
    }

    private function isMysqlActiveNameConflict(string $sqlState, mixed $driverCode, string $message): bool
    {
        if ($sqlState !== '23000') {
            return false;
        }

        if ($driverCode !== 1062) {
            return false;
        }

        return str_contains($message, 'ca_ws_def_name_unique');
    }

    private function isSqliteActiveNameConflict(string $sqlState, string $message): bool
    {
        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($message, 'connector_accounts.workspace_id, connector_accounts.connector_definition_id, connector_accounts.active_name_uniqueness_key');
    }
}
