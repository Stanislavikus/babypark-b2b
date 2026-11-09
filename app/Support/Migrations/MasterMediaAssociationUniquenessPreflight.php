<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MasterMediaAssociationUniquenessPreflight
{
    public const LOCALE_SCOPE_SQL = "COALESCE(NULLIF(LOWER(REPLACE(TRIM(locale), '_', '-')), ''), '#common')";

    public function assertNoDuplicates(string $table, string $ownerColumn): void
    {
        $duplicate = DB::table($table)
            ->select(['workspace_id', $ownerColumn, 'media_asset_id'])
            ->selectRaw(self::LOCALE_SCOPE_SQL.' AS locale_scope_key')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('workspace_id', $ownerColumn, 'media_asset_id')
            ->groupByRaw(self::LOCALE_SCOPE_SQL)
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot enforce %s media association uniqueness while duplicate owner/asset/locale rows exist.',
            $table,
        ));
    }
}
