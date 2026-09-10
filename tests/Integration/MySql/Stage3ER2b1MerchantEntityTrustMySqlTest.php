<?php

namespace Tests\Integration\MySql;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Models\ExternalRecordLink;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustReviewService;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\MySqlWorkspaceRowLockWaitProbe;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;
use Tests\TestCase;

class Stage3ER2b1MerchantEntityTrustMySqlTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithEntityTrustFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;

    private EntityTrustAdobeTransportResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only entity trust concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();

        $this->responder = new EntityTrustAdobeTransportResponder;
        $this->bindEntityTrustTransport($this->responder);
    }

    #[Test]
    public function concurrent_confirmations_race_for_same_sku_only_one_persists(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$productA, $variantA] = $this->createSimpleEntityTrustProduct($account->workspace, 'SHARED-RACE-SKU');
        [$productB, $variantB] = $this->createSimpleEntityTrustProduct($account->workspace, 'SHARED-RACE-SKU-B');

        $this->responder->registerProduct('SHARED-RACE-SKU', 3001, 'simple');
        $this->responder->registerProduct('SHARED-RACE-SKU-B', 3001, 'simple');

        $actor = $this->createEntityTrustActor($account->workspace);

        $reviewA = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productA->id,
        );

        $reviewB = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productB->id,
        );

        $ipcDir = sys_get_temp_dir().'/entity-trust-race-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();

        $processA = new Process([
            PHP_BINARY,
            $workerScript,
            'confirm-a',
            $account->workspace_id,
            $account->id,
            (string) $productA->id,
            $reviewA->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], base_path(), $env);
        $processA->setTimeout(120);
        $processA->start();

        $processB = new Process([
            PHP_BINARY,
            $workerScript,
            'confirm-b',
            $account->workspace_id,
            $account->id,
            (string) $productB->id,
            $reviewB->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], base_path(), $env);
        $processB->setTimeout(120);
        $processB->start();

        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode(), $processA->getErrorOutput());
        $this->assertSame(0, $processB->getExitCode(), $processB->getErrorOutput());

        $winner = trim((string) file_get_contents($ipcDir.'/winner'));
        $loser = trim((string) file_get_contents($ipcDir.'/loser'));

        $this->assertContains($winner, ['a', 'b']);
        $this->assertContains($loser, ['a', 'b']);
        $this->assertNotSame($winner, $loser);

        $this->assertSame(
            1,
            ExternalRecordLink::withoutWorkspaceScope()
                ->where('connector_account_id', $account->id)
                ->where('external_record_discriminator', '3001')
                ->count(),
        );
    }

    #[Test]
    public function discriminator_collision_is_rejected_under_mysql_locks(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$productA, $variantA] = $this->createSimpleEntityTrustProduct($account->workspace, 'DISC-A');
        [$productB, $variantB] = $this->createSimpleEntityTrustProduct($account->workspace, 'DISC-B');

        $this->responder->registerProduct('DISC-A', 4001, 'simple');
        $this->responder->registerProduct('DISC-B', 4001, 'simple');

        $actor = $this->createEntityTrustActor($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $account->workspace,
                $account->id,
                $variantB,
                'DISC-B',
                '4001',
                $this->createWorkspaceActor($account->workspace),
            ),
        );

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productA->id,
        );

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $productA->id,
                $review->reviewToken,
            );
            $this->fail('Expected discriminator collision.');
        } catch (EntityTrustException) {
            // expected
        }

        $this->assertNull(
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variantA->id)->first(),
        );
    }

    #[Test]
    public function settings_target_change_before_confirm_causes_review_target_mismatch(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'TARGET-RACE-SKU');
        $this->responder->registerProduct('TARGET-RACE-SKU', 6001, 'simple');

        $actor = $this->createEntityTrustActor($account->workspace);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $ipcDir = $this->createIpcDirectory('entity-trust-settings-wins');
        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();
        $newBaseUrl = 'https://changed-target.example.com';

        $boundary = $this->startWorker($workerScript, [
            'hold-boundary',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], $env);

        $this->waitForIpcFile($ipcDir.'/boundary_lock_acquired');

        $settings = $this->startWorker($workerScript, [
            'settings-target-update',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
            $newBaseUrl,
        ], $env);

        $settingsLockWait = MySqlWorkspaceRowLockWaitProbe::waitForForeignWorkspaceForUpdateWait($account->workspace_id);
        $this->assertStringContainsString('for update', strtolower($settingsLockWait['info']));

        $confirm = $this->startWorker($workerScript, [
            'confirm-trust',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], $env);

        $this->waitForIpcFile($ipcDir.'/settings_entered');
        $this->waitForIpcFile($ipcDir.'/confirm_entered');
        $this->assertFileDoesNotExist($ipcDir.'/settings_finished', 'Settings update must not finish while boundary lock is held.');
        $this->assertFileDoesNotExist($ipcDir.'/confirm_finished', 'Confirm must not finish while boundary lock is held.');

        $confirmLockWait = MySqlWorkspaceRowLockWaitProbe::waitForForeignWorkspaceForUpdateWait($account->workspace_id);
        $this->assertStringContainsString('for update', strtolower($confirmLockWait['info']));

        touch($ipcDir.'/release_boundary');

        $boundary->wait();
        $settings->wait();
        $confirm->wait();

        $this->assertSame(0, $boundary->getExitCode(), $boundary->getErrorOutput());
        $this->assertSame(0, $settings->getExitCode(), $settings->getErrorOutput());
        $this->assertSame(0, $confirm->getExitCode(), $confirm->getErrorOutput());
        $this->assertFileExists($ipcDir.'/boundary_released');
        $this->assertSame('success', file_get_contents($ipcDir.'/settings_result'));
        $this->assertSame(EntityTrustFailureReason::ReviewTargetMismatch->value, file_get_contents($ipcDir.'/confirm_result'));

        $account->refresh();
        $this->assertSame($newBaseUrl, $account->base_url);
        $this->assertSame(0, ExternalRecordLink::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
    }

    #[Test]
    public function confirm_before_target_change_rejects_settings_update(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, 'CONFIRM-FIRST-SKU');
        $this->responder->registerProduct('CONFIRM-FIRST-SKU', 6101, 'simple');

        $originalBaseUrl = (string) $account->base_url;
        $actor = $this->createEntityTrustActor($account->workspace);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $ipcDir = $this->createIpcDirectory('entity-trust-confirm-wins');
        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();

        $boundary = $this->startWorker($workerScript, [
            'hold-boundary',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], $env);

        $this->waitForIpcFile($ipcDir.'/boundary_lock_acquired');

        $confirm = $this->startWorker($workerScript, [
            'confirm-trust',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
            '',
            '6101',
            'CONFIRM-FIRST-SKU',
        ], $env);

        $confirmLockWait = MySqlWorkspaceRowLockWaitProbe::waitForForeignWorkspaceForUpdateWait($account->workspace_id);
        $this->assertStringContainsString('for update', strtolower($confirmLockWait['info']));
        $this->waitForIpcFile($ipcDir.'/confirm_entered');
        $this->assertFileDoesNotExist($ipcDir.'/confirm_finished', 'Confirm must not finish while boundary lock is held.');

        $settings = $this->startWorker($workerScript, [
            'settings-target-update',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
            'https://blocked-target.example.com',
        ], $env);

        $settingsLockWait = MySqlWorkspaceRowLockWaitProbe::waitForForeignWorkspaceForUpdateWait($account->workspace_id);
        $this->assertStringContainsString('for update', strtolower($settingsLockWait['info']));
        $this->waitForIpcFile($ipcDir.'/settings_entered');
        $this->assertFileDoesNotExist($ipcDir.'/settings_finished', 'Settings update must not finish while boundary lock is held.');

        touch($ipcDir.'/release_boundary');

        $boundary->wait();
        $confirm->wait();
        $settings->wait();

        $this->assertSame(0, $boundary->getExitCode(), $boundary->getErrorOutput());
        $this->assertSame(0, $confirm->getExitCode(), $confirm->getErrorOutput());
        $this->assertSame(0, $settings->getExitCode(), $settings->getErrorOutput());
        $this->assertSame('success', file_get_contents($ipcDir.'/confirm_result'));
        $this->assertSame('target_frozen', file_get_contents($ipcDir.'/settings_result'));
        $this->assertTrue(
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->exists(),
        );

        $account->refresh();
        $this->assertSame($originalBaseUrl, $account->base_url);
    }

    #[Test]
    public function credential_rotation_serializes_safely_with_confirm(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, 'CRED-RACE-SKU');
        $this->responder->registerProduct('CRED-RACE-SKU', 7001, 'simple');

        $originalBaseUrl = (string) $account->base_url;
        $originalStoreCode = (string) $account->store_code;
        $actor = $this->createEntityTrustActor($account->workspace);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $ipcDir = $this->createIpcDirectory('entity-trust-cred-race');
        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();

        $boundary = $this->startWorker($workerScript, [
            'hold-boundary',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], $env);

        $this->waitForIpcFile($ipcDir.'/boundary_lock_acquired');

        $confirm = $this->startWorker($workerScript, [
            'confirm-trust',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], $env);

        $confirmLockWait = MySqlWorkspaceRowLockWaitProbe::waitForForeignWorkspaceForUpdateWait($account->workspace_id);
        $this->assertStringContainsString('for update', strtolower($confirmLockWait['info']));
        $this->waitForIpcFile($ipcDir.'/confirm_entered');
        $this->assertFileDoesNotExist($ipcDir.'/confirm_finished', 'Confirm must not finish while boundary lock is held.');

        $credential = $this->startWorker($workerScript, [
            'credential-rotate',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], $env);

        $credentialLockWait = MySqlWorkspaceRowLockWaitProbe::waitForForeignWorkspaceForUpdateWait($account->workspace_id);
        $this->assertStringContainsString('for update', strtolower($credentialLockWait['info']));
        $this->waitForIpcFile($ipcDir.'/credential_entered');
        $this->assertFileDoesNotExist($ipcDir.'/credential_finished', 'Credential rotation must not finish while boundary lock is held.');

        touch($ipcDir.'/release_boundary');

        $boundary->wait();
        $confirm->wait();
        $credential->wait();

        $this->assertSame(0, $boundary->getExitCode(), $boundary->getErrorOutput());
        $this->assertSame(0, $confirm->getExitCode(), $confirm->getErrorOutput());
        $this->assertSame(0, $credential->getExitCode(), $credential->getErrorOutput());
        $this->assertSame('success', file_get_contents($ipcDir.'/confirm_result'));
        $this->assertSame('success', file_get_contents($ipcDir.'/credential_result'));
        $this->assertSame(
            1,
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count(),
        );

        $account->refresh();
        $this->assertSame($originalBaseUrl, $account->base_url);
        $this->assertSame($originalStoreCode, $account->store_code);
        $this->assertTrue(AdobePaaSCredentialMapper::hasCompleteSet($account->credentials));
        $this->assertSame('ck_race', $account->credentials['consumer_key'] ?? null);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function startWorker(string $workerScript, array $arguments, array $env): Process
    {
        $process = new Process(array_merge([PHP_BINARY, $workerScript], $arguments), base_path(), $env);
        $process->setTimeout(120);
        $process->start();

        return $process;
    }

    private function createIpcDirectory(string $prefix): string
    {
        $ipcDir = sys_get_temp_dir().'/'.$prefix.'-'.uniqid('', true);

        if (! mkdir($ipcDir) && ! is_dir($ipcDir)) {
            $this->fail('Could not create IPC directory.');
        }

        return $ipcDir;
    }

    private function waitForIpcFile(string $path, int $seconds = 60): void
    {
        $deadline = time() + $seconds;

        while (! file_exists($path) && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($path, "Timed out waiting for IPC file: {$path}");
    }

    /**
     * @return array<string, string>
     */
    private function mysqlWorkerEnvironment(): array
    {
        $connection = DB::connection();

        return array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => $connection->getName(),
            'DB_HOST' => (string) $connection->getConfig('host'),
            'DB_PORT' => (string) $connection->getConfig('port'),
            'DB_DATABASE' => (string) $connection->getConfig('database'),
            'DB_USERNAME' => (string) $connection->getConfig('username'),
            'DB_PASSWORD' => (string) $connection->getConfig('password'),
            'DB_SOCKET' => (string) ($connection->getConfig('unix_socket') ?? ''),
            'QUEUE_CONNECTION' => 'sync',
        ]);
    }
}
