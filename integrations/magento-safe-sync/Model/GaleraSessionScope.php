<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

final class GaleraSessionScope
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {}

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function execute(callable $callback): mixed
    {
        $connection = $this->resourceConnection->getConnection();
        $previous = $this->classifyWsrepSyncWait($connection);

        if ($previous === null) {
            return $callback();
        }

        $temporary = $previous | 1;

        try {
            $this->setWsrepSyncWait($connection, $temporary);
        } catch (\Throwable $exception) {
            throw SafeSyncReadException::causalReadUnavailable($exception);
        }

        try {
            return $callback();
        } finally {
            try {
                $this->setWsrepSyncWait($connection, $previous);
            } catch (\Throwable $exception) {
                $this->quarantineConnectionAfterRestoreFailure($exception);
            }
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
                throw SafeSyncReadException::causalReadUnavailable();
            }

            if ($this->readRequiredBooleanSessionVariable($connection, 'wsrep_dirty_reads')) {
                throw SafeSyncReadException::causalReadUnavailable();
            }

            if (! $this->readRequiredBooleanStatus($connection, 'wsrep_connected')) {
                throw SafeSyncReadException::causalReadUnavailable();
            }

            if (! $this->readRequiredBooleanStatus($connection, 'wsrep_ready')) {
                throw SafeSyncReadException::causalReadUnavailable();
            }

            if (! $this->isPrimaryCluster($this->readRequiredStatusValue($connection, 'wsrep_cluster_status'))) {
                throw SafeSyncReadException::causalReadUnavailable();
            }

            return $this->readRequiredWsrepSyncWait($connection);
        } catch (SafeSyncReadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw SafeSyncReadException::causalReadUnavailable($exception);
        }
    }

    private function setWsrepSyncWait(AdapterInterface $connection, int $value): void
    {
        $connection->query(sprintf('SET SESSION wsrep_sync_wait = %d', $value));
    }

    private function quarantineConnectionAfterRestoreFailure(\Throwable $restoreException): never
    {
        try {
            $this->resourceConnection->closeConnection();
        } catch (\Throwable $quarantineException) {
            throw SafeSyncReadException::wsrepRestoreFailed(
                new \RuntimeException(
                    sprintf(
                        'safe_sync_wsrep_connection_quarantine_failed:%s',
                        $quarantineException::class,
                    ),
                    0,
                    $restoreException,
                ),
            );
        }

        throw SafeSyncReadException::wsrepRestoreFailed($restoreException);
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
            throw SafeSyncReadException::causalReadUnavailable();
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
            throw SafeSyncReadException::causalReadUnavailable();
        }

        $integerValue = (int) $value;

        if ($integerValue < 0 || $integerValue > 15) {
            throw SafeSyncReadException::causalReadUnavailable();
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
            throw SafeSyncReadException::causalReadUnavailable();
        }

        return (string) $value;
    }

    private function parseRequiredBoolean(?string $value): bool
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'on' => true,
            '0', 'off' => false,
            default => throw SafeSyncReadException::causalReadUnavailable(),
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
