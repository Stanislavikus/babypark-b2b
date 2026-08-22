<?php

namespace Tests\Integration\MySql;

use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
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
    public function mysql_fallback_xor_triggers_can_be_created_enforced_and_dropped(): void
    {
        $this->assertTrue(Schema::hasTable('external_record_links'));

        $this->enableMysqlTriggerCreationUnderBinaryLogging();

        $migration = require database_path('migrations/2026_08_19_110000_external_record_links.php');
        $reflection = new ReflectionObject($migration);
        $createFallbackTriggers = $reflection->getMethod('createMysqlSubjectXorTriggers');
        $createFallbackTriggers->setAccessible(true);

        DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_update');

        $createFallbackTriggers->invoke($migration);

        $triggerNames = collect(DB::select("
            SELECT TRIGGER_NAME
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND EVENT_OBJECT_TABLE = 'external_record_links'
              AND TRIGGER_NAME IN ('erl_subject_xor_insert', 'erl_subject_xor_update')
        "))->pluck('TRIGGER_NAME')->sort()->values()->all();

        $this->assertSame(['erl_subject_xor_insert', 'erl_subject_xor_update'], $triggerNames);

        $account = $this->createConnectorAccount();
        $product = $this->createExternalRecordLinkProduct($account->workspace);
        $variant = $this->createExternalRecordLinkVariant($account->workspace, $product);

        try {
            DB::table('external_record_links')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'connector_account_id' => $account->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'external_identifier' => 'FALLBACK-INVALID-INSERT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected fallback trigger to reject invalid XOR insert.');
        } catch (QueryException) {
            // expected
        }

        $linkId = (string) Str::uuid();
        DB::table('external_record_links')->insert([
            'id' => $linkId,
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => 'FALLBACK-VALID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::statement(
                'UPDATE external_record_links SET product_id = ?, product_variant_id = ? WHERE id = ?',
                [null, null, $linkId],
            );
            $this->fail('Expected fallback trigger to reject invalid XOR update.');
        } catch (QueryException) {
            // expected
        }

        DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS erl_subject_xor_update');

        $remainingTriggers = DB::selectOne("
            SELECT COUNT(*) AS count
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND EVENT_OBJECT_TABLE = 'external_record_links'
              AND TRIGGER_NAME IN ('erl_subject_xor_insert', 'erl_subject_xor_update')
        ");

        $this->assertSame(0, (int) $remainingTriggers->count);
    }

    private function enableMysqlTriggerCreationUnderBinaryLogging(): void
    {
        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (string) config('database.connections.mysql.port', '3306');
        $socket = (string) config('database.connections.mysql.unix_socket', '');
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket}"
            : "mysql:host={$host};port={$port}";
        $rootPasswordCandidates = array_values(array_unique(array_filter([
            env('MYSQL_ROOT_PASSWORD'),
            'rootsecret',
            '',
        ], static fn ($password) => $password !== null)));

        foreach ($rootPasswordCandidates as $rootPassword) {
            try {
                $root = new \PDO(
                    $dsn,
                    'root',
                    (string) $rootPassword,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
                );
                $root->exec('SET GLOBAL log_bin_trust_function_creators = 1');

                return;
            } catch (\Throwable) {
                // Try the next root credential candidate.
            }
        }

        try {
            DB::statement('SET GLOBAL log_bin_trust_function_creators = 1');
        } catch (\Throwable $exception) {
            $this->markTestSkipped(
                'Could not enable log_bin_trust_function_creators for fallback trigger creation: '.$exception->getMessage(),
            );
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
    public function stage_3e_r2a_provenance_migration_rolls_back_and_reapplies(): void
    {
        $this->assertTrue(Schema::hasColumn('external_record_links', 'trust_origin'));
        $this->assertTrue(Schema::hasColumn('external_record_links', 'external_record_discriminator'));
        $this->assertTrue(Schema::hasColumn('external_record_links', 'established_by_workspace_user_id'));
        $this->assertTrue(Schema::hasColumn('external_record_links', 'established_at'));

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_22_100000_external_record_link_provenance.php',
        ]);

        $this->assertFalse(Schema::hasColumn('external_record_links', 'trust_origin'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasColumn('external_record_links', 'trust_origin'));
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
