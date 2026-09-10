<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Test-only observability for MySQL connector_accounts row-lock contention via PROCESSLIST.
 */
final class MySqlConnectorAccountRowLockWaitProbe
{
    /**
     * @return array{id:int,info:string,state:?string}
     */
    public static function findForeignConnectorAccountForUpdateWait(string $accountId, ?int $excludeConnectionId = null): ?array
    {
        $excludeConnectionId ??= (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;

        $rows = DB::select(
            'SELECT ID, INFO, STATE
             FROM information_schema.PROCESSLIST
             WHERE DB = DATABASE()
               AND ID <> ?
               AND COMMAND != ?
               AND INFO IS NOT NULL
               AND LOWER(INFO) LIKE ?
               AND LOWER(INFO) LIKE ?
               AND INFO LIKE ?',
            [
                $excludeConnectionId,
                'Sleep',
                '%connector_accounts%',
                '%for update%',
                '%'.$accountId.'%',
            ],
        );

        foreach ($rows as $row) {
            return [
                'id' => (int) $row->ID,
                'info' => (string) $row->INFO,
                'state' => $row->STATE !== null ? (string) $row->STATE : null,
            ];
        }

        return null;
    }

    public static function waitForForeignConnectorAccountForUpdateWait(
        string $accountId,
        int $seconds = 60,
        ?int $excludeConnectionId = null,
    ): array {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $match = self::findForeignConnectorAccountForUpdateWait($accountId, $excludeConnectionId);

            if ($match !== null) {
                return $match;
            }

            usleep(50_000);
        }

        throw new \RuntimeException(
            "Timed out waiting for foreign PROCESSLIST row with connector_accounts FOR UPDATE on {$accountId}.",
        );
    }
}
