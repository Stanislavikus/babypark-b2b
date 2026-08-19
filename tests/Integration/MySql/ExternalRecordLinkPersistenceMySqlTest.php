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
