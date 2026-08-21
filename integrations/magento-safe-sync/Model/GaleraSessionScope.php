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
        $previous = $this->readWsrepSyncWait($connection);

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
                throw SafeSyncReadException::wsrepRestoreFailed($exception);
            }
        }
    }

    private function readWsrepSyncWait(AdapterInterface $connection): ?int
    {
        $row = $connection->fetchRow("SHOW SESSION VARIABLES LIKE 'wsrep_sync_wait'");

        if (! is_array($row) || ! array_key_exists('Value', $row)) {
            return null;
        }

        if (! is_numeric($row['Value'])) {
            throw SafeSyncReadException::causalReadUnavailable();
        }

        return (int) $row['Value'];
    }

    private function setWsrepSyncWait(AdapterInterface $connection, int $value): void
    {
        $connection->query(sprintf('SET SESSION wsrep_sync_wait = %d', $value));
    }
}
