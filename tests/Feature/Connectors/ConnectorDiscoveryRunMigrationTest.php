<?php

namespace Tests\Feature\Connectors;

use App\Models\ConnectorDiscoveryRun;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorDiscoveryRunMigrationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    private const TABLE = 'connector_discovery_runs';

    private const INDEX = 'cdr_active_lookup_idx';

    private const INDEX_COLUMNS = [
        'workspace_id',
        'connector_account_id',
        'connector_schema_source_id',
        'status',
        'retry_until_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function migration_adds_queue_lifecycle_columns_with_correct_schema(): void
    {
        $this->assertColumnSchema('execution_attempts', [
            'sqlite_type' => 'INTEGER',
            'mysql_type' => 'tinyint unsigned',
            'nullable' => false,
            'default' => '0',
        ]);
        $this->assertColumnSchema('retry_until_at', [
            'sqlite_type' => 'datetime',
            'mysql_type' => 'timestamp',
            'nullable' => true,
            'default' => null,
        ]);
        $this->assertColumnSchema('next_attempt_at', [
            'sqlite_type' => 'datetime',
            'mysql_type' => 'timestamp',
            'nullable' => true,
            'default' => null,
        ]);

        $this->assertSame(self::INDEX_COLUMNS, $this->indexColumns(self::TABLE, self::INDEX));
    }

    #[Test]
    public function model_casts_new_columns_correctly(): void
    {
        $retryUntil = now()->addHour();
        $nextAttempt = now()->addSeconds(60);

        $row = ConnectorDiscoveryRun::factory()->create([
            'execution_attempts' => 2,
            'retry_until_at' => $retryUntil,
            'next_attempt_at' => $nextAttempt,
            'started_at' => null,
        ]);

        $row->refresh();

        $this->assertIsInt($row->execution_attempts);
        $this->assertSame(2, $row->execution_attempts);
        $this->assertInstanceOf(Carbon::class, $row->retry_until_at);
        $this->assertInstanceOf(Carbon::class, $row->next_attempt_at);
        $this->assertSame($retryUntil->getTimestamp(), $row->retry_until_at->getTimestamp());
        $this->assertSame($nextAttempt->getTimestamp(), $row->next_attempt_at->getTimestamp());
    }

    #[Test]
    public function down_removes_index_and_columns_and_up_succeeds_again(): void
    {
        $this->rollbackThrough('2026_08_01_184500_connector_discovery_run_queue_lifecycle');

        $this->assertFalse(Schema::hasColumn(self::TABLE, 'execution_attempts'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'retry_until_at'));
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'next_attempt_at'));
        $this->assertFalse($this->indexExists(self::TABLE, self::INDEX));

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'execution_attempts'));
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'retry_until_at'));
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'next_attempt_at'));
        $this->assertSame(self::INDEX_COLUMNS, $this->indexColumns(self::TABLE, self::INDEX));
    }

    /**
     * @param  array{sqlite_type: string, mysql_type: string, nullable: bool, default: string|null}  $expected
     */
    private function assertColumnSchema(string $column, array $expected): void
    {
        $this->assertTrue(Schema::hasColumn(self::TABLE, $column), "Missing column: {$column}");

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $info = collect(DB::select('PRAGMA table_info('.self::TABLE.')'))
                ->firstWhere('name', $column);

            $this->assertNotNull($info, "SQLite PRAGMA missing column: {$column}");
            $this->assertSame($expected['sqlite_type'], $info->type, "{$column} SQLite type");
            $this->assertSame($expected['nullable'] ? 0 : 1, (int) $info->notnull, "{$column} SQLite nullability");

            if ($expected['default'] === null) {
                $this->assertNull($info->dflt_value, "{$column} SQLite default");
            } else {
                $this->assertSame(
                    $expected['default'],
                    trim((string) $info->dflt_value, "'"),
                    "{$column} SQLite default",
                );
            }

            return;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $info = DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?
             LIMIT 1',
            [$database, self::TABLE, $column],
        );

        $this->assertNotNull($info, "MySQL information_schema missing column: {$column}");
        $this->assertSame($expected['mysql_type'], strtolower((string) $info->column_type), "{$column} MySQL type");
        $this->assertSame($expected['nullable'] ? 'YES' : 'NO', $info->is_nullable, "{$column} MySQL nullability");

        if ($expected['default'] === null) {
            $this->assertNull($info->column_default, "{$column} MySQL default");
        } else {
            $this->assertSame($expected['default'], (string) $info->column_default, "{$column} MySQL default");
        }
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $index): array
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_info('{$index}')");

            return array_map(
                static fn (object $row): string => (string) $row->name,
                $rows,
            );
        }

        $database = $connection->getDatabaseName();

        return array_map(
            static fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME),
            $connection->select(
                'SELECT column_name
                 FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                [$database, $table, $index],
            ),
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index],
        );

        return $result !== [];
    }

    private function rollbackThrough(string $targetMigration): void
    {
        $migrations = DB::table('migrations')
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration')
            ->values();

        $position = $migrations->search($targetMigration);

        $this->assertNotSame(
            false,
            $position,
            "Target migration is not recorded as applied: {$targetMigration}",
        );

        $this->artisan('migrate:rollback', [
            '--step' => ((int) $position) + 1,
        ])->assertExitCode(0);
    }
}
