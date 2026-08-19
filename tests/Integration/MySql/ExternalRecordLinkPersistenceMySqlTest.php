<?php

namespace Tests\Integration\MySql;

use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsExternalRecordLinkDatabaseContract;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ExternalRecordLinkPersistenceMySqlTest extends TestCase
{
    use AssertsExternalRecordLinkDatabaseContract;
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only integration test.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function mysql_version_supports_check_constraint_enforcement(): void
    {
        $version = DB::selectOne('SELECT VERSION() as version')->version;
        $this->assertMatchesRegularExpression('/^8\.0\.(\d+)/', (string) $version);

        preg_match('/^8\.0\.(\d+)/', (string) $version, $matches);
        $this->assertGreaterThanOrEqual(16, (int) ($matches[1] ?? 0), 'MySQL VERSION(): '.$version);
    }

    #[Test]
    public function external_record_link_xor_enforcement_matches_mysql_version_capability(): void
    {
        $version = (string) DB::selectOne('SELECT VERSION() as version')->version;
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches);

        $major = (int) ($matches[1] ?? 0);
        $minor = (int) ($matches[2] ?? 0);
        $patch = (int) ($matches[3] ?? 0);
        $supportsNamedCheck = $major > 8
            || ($major === 8 && $minor > 0)
            || ($major === 8 && $minor === 0 && $patch >= 16);

        $checkConstraint = DB::selectOne("
            SELECT COUNT(*) AS count
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'external_record_links'
              AND CONSTRAINT_NAME = 'erl_subject_xor_check'
              AND CONSTRAINT_TYPE = 'CHECK'
        ");

        $insertTrigger = DB::selectOne("
            SELECT COUNT(*) AS count
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND EVENT_OBJECT_TABLE = 'external_record_links'
              AND TRIGGER_NAME = 'erl_subject_xor_insert'
        ");

        if ($supportsNamedCheck) {
            $this->assertSame(1, (int) $checkConstraint->count);
            $this->assertSame(0, (int) $insertTrigger->count);
        } else {
            $this->assertSame(0, (int) $checkConstraint->count);
            $this->assertSame(1, (int) $insertTrigger->count);
        }
    }

    #[Test]
    public function stage_3a_sync_run_execution_safety_migration_rolls_back_and_reapplies(): void
    {
        $version = DB::selectOne('SELECT VERSION() as version')->version;

        $this->assertTrue(Schema::hasColumn('sync_runs', 'recoverable_after'));

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_19_100000_sync_run_execution_safety.php',
        ]);

        $this->assertFalse(Schema::hasColumn('sync_runs', 'recoverable_after'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasColumn('sync_runs', 'recoverable_after'));
        $this->assertNotEmpty($version);
    }

    #[Test]
    public function stage_3a_external_record_links_migration_rolls_back_and_reapplies(): void
    {
        $version = DB::selectOne('SELECT VERSION() as version')->version;

        $this->assertTrue(Schema::hasTable('external_record_links'));

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_19_110000_external_record_links.php',
        ]);

        Artisan::call('migrate');
        $this->assertTrue(Schema::hasTable('external_record_links'));

        $this->assertNotEmpty($version);
    }

    #[Test]
    public function stage_3a_migrations_roll_back_and_reapply_in_pair(): void
    {
        $version = DB::selectOne('SELECT VERSION() as version')->version;

        Artisan::call('migrate:rollback', ['--step' => 2]);

        $this->assertFalse(Schema::hasTable('external_record_links'));
        $this->assertFalse(Schema::hasColumn('sync_runs', 'recoverable_after'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('external_record_links'));
        $this->assertTrue(Schema::hasColumn('sync_runs', 'recoverable_after'));
        $this->assertNotEmpty($version);
    }
}
