<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Test-only observability for MySQL workspace row-lock contention via PROCESSLIST.
 */
final class MySqlWorkspaceRowLockWaitProbe
{
    /**
     * @return array{id:int,info:string,state:?string}
     */
    public static function findForeignWorkspaceForUpdateWait(string $workspaceId, ?int $excludeConnectionId = null): ?array
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
                '%workspaces%',
                '%for update%',
                '%'.$workspaceId.'%',
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

    public static function waitForForeignWorkspaceForUpdateWait(
        string $workspaceId,
        int $seconds = 60,
        ?int $excludeConnectionId = null,
    ): array {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $match = self::findForeignWorkspaceForUpdateWait($workspaceId, $excludeConnectionId);

            if ($match !== null) {
                return $match;
            }

            usleep(50_000);
        }

        throw new \RuntimeException(
            "Timed out waiting for foreign PROCESSLIST row with workspaces FOR UPDATE on {$workspaceId}.",
        );
    }
}
