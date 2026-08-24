<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use B2BPlatform\MagentoSafeSync\Model\Connection\ConnectionQuarantine;
use Magento\Framework\DB\Adapter\AdapterInterface;

final class GaleraWriteSession
{
    public function __construct(
        private readonly ConnectionQuarantine $connectionQuarantine,
    ) {}

    /**
     * @return array{previous:?int}
     */
    public function establish(AdapterInterface $connection): array
    {
        $previous = $this->classifyWsrepSyncWait($connection);

        if ($previous === null) {
            return ['previous' => null];
        }

        try {
            $this->setWsrepSyncWait($connection, $previous | 1);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('safe_sync_causal_write_unavailable', 0, $exception);
        }

        return ['previous' => $previous];
    }

    /**
     * @param  array{previous:?int}  $state
     */
    public function restore(AdapterInterface $connection, array $state): void
    {
        if ($state['previous'] === null) {
            return;
        }

        if ($this->transactionLevel($connection) !== 0) {
            $restoreException = new \RuntimeException('safe_sync_wsrep_restore_before_transaction_level_zero');
            $this->quarantineConnectionAfterRestoreFailure($connection, $restoreException);

            throw $restoreException;
        }

        try {
            $this->setWsrepSyncWait($connection, $state['previous']);
        } catch (\Throwable $restoreException) {
            $this->quarantineConnectionAfterRestoreFailure($connection, $restoreException);
            throw new \RuntimeException('safe_sync_wsrep_restore_failed', 0, $restoreException);
        }
    }

    private function classifyWsrepSyncWait(AdapterInterface $connection): ?int
    {
        try {
            $provider = $this->readOptionalVariable($connection, 'wsrep_provider');

            if ($provider === null || $this->isInactiveProvider($provider)) {
                return null;
            }

            if (! $this->readRequiredBooleanSessionVariable($connection, 'wsrep_on')) {
                throw new \RuntimeException('safe_sync_causal_write_unavailable');
            }

            if ($this->readRequiredBooleanSessionVariable($connection, 'wsrep_dirty_reads')) {
                throw new \RuntimeException('safe_sync_causal_write_unavailable');
            }

            if (! $this->readRequiredBooleanStatus($connection, 'wsrep_connected')) {
                throw new \RuntimeException('safe_sync_causal_write_unavailable');
            }

            if (! $this->readRequiredBooleanStatus($connection, 'wsrep_ready')) {
                throw new \RuntimeException('safe_sync_causal_write_unavailable');
            }

            if (! $this->isPrimaryCluster($this->readRequiredStatusValue($connection, 'wsrep_cluster_status'))) {
                throw new \RuntimeException('safe_sync_causal_write_unavailable');
            }

            return $this->readRequiredWsrepSyncWait($connection);
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \RuntimeException('safe_sync_causal_write_unavailable', 0, $exception);
        }
    }

    private function setWsrepSyncWait(AdapterInterface $connection, int $value): void
    {
        $connection->query(sprintf('SET SESSION wsrep_sync_wait = %d', $value));
    }

    private function transactionLevel(AdapterInterface $connection): int
    {
        return method_exists($connection, 'getTransactionLevel') ? (int) $connection->getTransactionLevel() : 0;
    }

    private function quarantineConnectionAfterRestoreFailure(
        AdapterInterface $connection,
        \Throwable $restoreException,
    ): void {
        $result = $this->connectionQuarantine->quarantine($connection);

        if (! $result['success']) {
            throw new \RuntimeException(
                sprintf(
                    'safe_sync_wsrep_connection_quarantine_failed:%s',
                    $result['callback_clear_failed'] ? 'callback_clear_failed' : 'reset_failed',
                ),
                0,
                $restoreException,
            );
        }
    }

    private function readOptionalVariable(AdapterInterface $connection, string $name): ?string
    {
        return $this->readOptionalShowValue(
            $connection,
            sprintf("SHOW VARIABLES LIKE '%s'", $name),
        );
    }

    private function readRequiredBooleanSessionVariable(AdapterInterface $connection, string $name): bool
    {
        return $this->parseRequiredBoolean(
            $this->readOptionalShowValue(
                $connection,
                sprintf("SHOW SESSION VARIABLES LIKE '%s'", $name),
            ),
        );
    }

    private function readRequiredBooleanStatus(AdapterInterface $connection, string $name): bool
    {
        return $this->parseRequiredBoolean(
            $this->readOptionalShowValue(
                $connection,
                sprintf("SHOW STATUS LIKE '%s'", $name),
            ),
        );
    }

    private function readRequiredStatusValue(AdapterInterface $connection, string $name): string
    {
        $value = $this->readOptionalShowValue(
            $connection,
            sprintf("SHOW STATUS LIKE '%s'", $name),
        );

        if ($value === null) {
            throw new \RuntimeException('safe_sync_causal_write_unavailable');
        }

        return $value;
    }

    private function readRequiredWsrepSyncWait(AdapterInterface $connection): int
    {
        $value = $this->readOptionalShowValue(
            $connection,
            "SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'",
        );

        if ($value === null || preg_match('/^(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new \RuntimeException('safe_sync_causal_write_unavailable');
        }

        $integerValue = (int) $value;

        if ($integerValue < 0 || $integerValue > 15) {
            throw new \RuntimeException('safe_sync_causal_write_unavailable');
        }

        return $integerValue;
    }

    private function readOptionalShowValue(AdapterInterface $connection, string $query): ?string
    {
        $row = $connection->fetchRow($query);

        if (! is_array($row)) {
            return null;
        }

        $value = $row['Value'] ?? $row['VALUE'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new \RuntimeException('safe_sync_causal_write_unavailable');
        }

        return (string) $value;
    }

    private function parseRequiredBoolean(?string $value): bool
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'on' => true,
            '0', 'off' => false,
            default => throw new \RuntimeException('safe_sync_causal_write_unavailable'),
        };
    }

    private function isInactiveProvider(string $provider): bool
    {
        return in_array(
            strtolower(trim($provider)),
            ['', 'none', 'null'],
            true,
        );
    }

    private function isPrimaryCluster(string $clusterStatus): bool
    {
        return strtolower(trim($clusterStatus)) === 'primary';
    }
}
