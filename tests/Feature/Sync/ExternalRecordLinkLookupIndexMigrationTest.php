<?php

namespace Tests\Feature\Sync;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExternalRecordLinkLookupIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'external_record_links';

    private const INDEX = 'erl_ws_account_trust_discriminator_idx';

    private const COLUMNS = [
        'workspace_id',
        'connector_account_id',
        'trust_origin',
        'external_record_discriminator',
    ];

    #[Test]
    public function trusted_remote_identifier_lookup_has_the_expected_composite_index(): void
    {
        $this->assertSame(self::COLUMNS, $this->indexColumns(self::TABLE, self::INDEX));
    }

    #[Test]
    public function latest_migration_rolls_the_lookup_index_back_and_forward_cleanly(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_17_090000_add_entity_trust_lookup_index_to_external_record_links.php',
        ])->assertExitCode(0);
        $this->assertFalse($this->indexExists(self::TABLE, self::INDEX));

        $this->artisan('migrate')->assertExitCode(0);
        $this->assertSame(self::COLUMNS, $this->indexColumns(self::TABLE, self::INDEX));
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index): array
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return array_map(
                static fn (object $row): string => (string) $row->name,
                $connection->select("PRAGMA index_info('{$index}')"),
            );
        }

        return array_map(
            static fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME),
            $connection->select(
                'SELECT column_name FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                [$connection->getDatabaseName(), $table, $index],
            ),
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $index);
        }

        return DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$connection->getDatabaseName(), $table, $index],
        ) !== [];
    }
}
