<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorConnectionCheckStatus;
use App\Models\ConnectorConnectionCheck;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorConnectionCheckMigrationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function migration_adds_queue_lifecycle_columns_and_index(): void
    {
        $this->assertTrue(Schema::hasColumn('connector_connection_checks', 'execution_attempts'));
        $this->assertTrue(Schema::hasColumn('connector_connection_checks', 'retry_until_at'));
        $this->assertTrue(Schema::hasColumn('connector_connection_checks', 'next_attempt_at'));

        $this->assertTrue($this->indexExists('connector_connection_checks', 'connector_checks_active_lookup_idx'));
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
            [$database, $table, $index]
        );

        return $result !== [];
    }

    #[Test]
    public function model_casts_new_columns_correctly(): void
    {
        $account = $this->createConnectorAccount();
        $retryUntil = now()->addMinutes(15);
        $nextAttempt = now()->addSeconds(30);

        $row = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Queued,
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
    }

    #[Test]
    public function down_backfills_nonterminal_rows_with_job_failed_not_attempts_exhausted(): void
    {
        $account = $this->createConnectorAccount();

        $queuedId = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Queued,
            'execution_attempts' => 0,
            'retry_until_at' => now()->addMinutes(15),
            'started_at' => null,
        ])->id;

        $runningWithVendorId = ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'trigger' => 'manual',
            'status' => ConnectorConnectionCheckStatus::Running,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addMinutes(10),
            'started_at' => now(),
            'error_code' => 'adobe_rate_limited',
            'cause_category' => 'rate_limit',
            'actionability' => 'automatic_retry',
            'user_message_key' => 'connectors.errors.rate_limited',
            'technical_summary' => 'HTTP 429 (adobe_rate_limited)',
        ])->id;

        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        $queued = DB::table('connector_connection_checks')->where('id', $queuedId)->first();
        $this->assertSame('failed', $queued->status);
        $this->assertSame('connection_check_job_failed', $queued->error_code);
        $this->assertSame('queue_job_failed', $queued->technical_summary);
        $this->assertNotSame('connection_check_attempts_exhausted_without_result', $queued->error_code);

        $running = DB::table('connector_connection_checks')->where('id', $runningWithVendorId)->first();
        $this->assertSame('failed', $running->status);
        $this->assertSame('adobe_rate_limited', $running->error_code);
        $this->assertSame('rate_limit', $running->cause_category);
        $this->assertSame('automatic_retry', $running->actionability);

        $this->artisan('migrate')->assertExitCode(0);
    }
}
