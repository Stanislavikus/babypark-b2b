<?php

namespace Tests\Integration\MySql;

use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Models\FieldMapping;
use App\Models\FieldOptionMapping;
use App\Services\Sync\FieldMappingMutationService;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

class FieldOptionMappingMutationConcurrencyMySqlTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;

    #[Test]
    public function concurrent_option_mapping_mutations_serialize_on_configuration_row_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshotWithOptions($account, [
            'color' => [
                ['value' => '93', 'label' => 'Blue'],
                ['value' => '94', 'label' => 'Pink'],
            ],
        ]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        $ipcDir = sys_get_temp_dir().'/field-option-mapping-concurrency-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/FieldOptionMappingMutationConcurrencyWorker.php');

        $lockProcess = new Process([
            $phpBinary,
            $workerScript,
            'hold-lock',
            $account->id,
            $configuration->id,
            $mapping->id,
            $ipcDir,
        ], base_path());
        $lockProcess->setTimeout(120);
        $lockProcess->start();

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/lock_acquired') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/lock_acquired', 'Lock holder did not acquire configuration row lock.');

        $confirmProcess = new Process([
            $phpBinary,
            $workerScript,
            'confirm-blue',
            $account->id,
            $configuration->id,
            $mapping->id,
            $ipcDir,
        ], base_path());
        $confirmProcess->setTimeout(120);
        $confirmProcess->start();

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/confirm_before_coordinator') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/confirm_before_coordinator', 'Confirm worker did not start.');
        $this->assertFileDoesNotExist($ipcDir.'/confirm_finished', 'Confirm finished while lock was still held.');

        touch($ipcDir.'/release_lock');

        $lockProcess->wait();
        $confirmProcess->wait();

        $this->assertFileExists($ipcDir.'/lock_released', 'Lock holder did not release.');
        $this->assertFileExists($ipcDir.'/confirm_finished', 'Confirm worker did not finish.');
        $this->assertSame('success', file_get_contents($ipcDir.'/confirm_result'));
        $this->assertSame(0, $lockProcess->getExitCode(), $lockProcess->getErrorOutput());
        $this->assertSame(0, $confirmProcess->getExitCode(), $confirmProcess->getErrorOutput());

        $this->assertDatabaseHas('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);
    }

    #[Test]
    public function stale_option_mapping_mutation_fails_when_revision_changes_under_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();
        $this->configureSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
            [SyncDataDomain::Products, SyncSemanticOperation::Export],
        ]);

        $account = $this->createSyncSupportAccount();
        $configuration = $this->createProductsSyncConfiguration($account);
        $binding = $this->productVariantBinding('color');

        $this->publishAuthoritativeSnapshotWithOptions($account, [
            'color' => [
                ['value' => '93', 'label' => 'Blue'],
                ['value' => '94', 'label' => 'Pink'],
            ],
        ]);

        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $binding->id,
            'color',
        );

        $mapping = FieldMapping::withoutWorkspaceScope()
            ->where('sync_configuration_id', $configuration->id)
            ->where('field_binding_id', $binding->id)
            ->sole();

        FieldOptionMapping::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
            'external_option_value' => '93',
        ]);

        $ipcDir = sys_get_temp_dir().'/field-option-mapping-stale-'.uniqid('', true);
        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        $phpBinary = PHP_BINARY;
        $workerScript = base_path('tests/Support/FieldOptionMappingMutationConcurrencyWorker.php');

        $lockProcess = new Process([
            $phpBinary,
            $workerScript,
            'hold-lock-delete',
            $account->id,
            $configuration->id,
            $mapping->id,
            $ipcDir,
        ], base_path());
        $lockProcess->setTimeout(120);
        $lockProcess->start();

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/lock_acquired') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/lock_acquired', 'Lock holder did not acquire configuration row lock.');

        $staleRemoveProcess = new Process([
            $phpBinary,
            $workerScript,
            'stale-remove',
            $account->id,
            $configuration->id,
            $mapping->id,
            $ipcDir,
        ], base_path());
        $staleRemoveProcess->setTimeout(120);
        $staleRemoveProcess->start();

        $deadline = time() + 60;
        while (! file_exists($ipcDir.'/stale_remove_started') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/stale_remove_started', 'Stale remove worker did not start.');

        $lockProcess->wait();
        $staleRemoveProcess->wait();

        $this->assertFileExists($ipcDir.'/mapping_deleted', 'Lock holder did not delete mapping under lock.');
        $this->assertFileExists($ipcDir.'/stale_remove_finished', 'Stale remove worker did not finish.');
        $this->assertSame('stale', file_get_contents($ipcDir.'/stale_remove_result'));
        $this->assertSame(0, $lockProcess->getExitCode(), $lockProcess->getErrorOutput());
        $this->assertSame(0, $staleRemoveProcess->getExitCode(), $staleRemoveProcess->getErrorOutput());

        $this->assertDatabaseMissing('field_option_mappings', [
            'field_mapping_id' => $mapping->id,
            'internal_option_key' => 'blue',
        ]);
    }
}
