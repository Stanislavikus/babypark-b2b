<?php

namespace Tests\Integration\MySql;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Models\ExternalRecordLink;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\UpdateConnectorAccountInput;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustReviewService;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountTargetFrozenException;
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
        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $ipcDir = sys_get_temp_dir().'/entity-trust-target-race-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();

        $targetChanger = new Process([
            PHP_BINARY,
            $workerScript,
            'change-target-then-release',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
            'https://changed-target.example.com',
        ], base_path(), $env);
        $targetChanger->setTimeout(120);
        $targetChanger->start();

        $deadline = time() + 30;
        while (! file_exists($ipcDir.'/target_lock_acquired') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/target_lock_acquired');

        touch($ipcDir.'/proceed_target_change');
        $targetChanger->wait();
        $this->assertSame(0, $targetChanger->getExitCode(), $targetChanger->getErrorOutput());
        $this->assertFileExists($ipcDir.'/target_changed');

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                $review->reviewToken,
            );
            $this->fail('Expected review target mismatch.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::ReviewTargetMismatch, $exception->reason);
        }

        $this->assertSame(0, ExternalRecordLink::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
    }

    #[Test]
    public function confirm_before_target_change_rejects_settings_update(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, 'CONFIRM-FIRST-SKU');
        $this->responder->registerProduct('CONFIRM-FIRST-SKU', 6101, 'simple');

        $actor = $this->createEntityTrustActor($account->workspace);
        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $ipcDir = sys_get_temp_dir().'/entity-trust-confirm-first-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();

        $lockHolder = new Process([
            PHP_BINARY,
            $workerScript,
            'hold-account-lock',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], base_path(), $env);
        $lockHolder->setTimeout(120);
        $lockHolder->start();

        $confirmWorker = new Process([
            PHP_BINARY,
            $workerScript,
            'confirm-after-lock-release',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], base_path(), $env);
        $confirmWorker->setTimeout(120);
        $confirmWorker->start();

        $deadline = time() + 30;
        while (! file_exists($ipcDir.'/confirm_waiting_for_lock_release') && time() < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($ipcDir.'/confirm_waiting_for_lock_release');
        $this->assertFileDoesNotExist($ipcDir.'/confirm_finished');

        touch($ipcDir.'/release_account_lock');
        $confirmWorker->wait();
        $lockHolder->wait();

        $this->assertSame(0, $confirmWorker->getExitCode(), $confirmWorker->getErrorOutput());
        $this->assertSame('success', file_get_contents($ipcDir.'/confirm_result'));
        $this->assertTrue(
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->exists(),
        );

        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::MANAGE_CONNECTOR_ACCOUNTS,
        ]);

        try {
            app(ConnectorAccountSettingsService::class)->update(
                $actor,
                $account->workspace,
                $account->id,
                UpdateConnectorAccountInput::adobePaas(
                    baseUrl: 'https://blocked-target.example.com',
                    storeCode: 'default',
                    tenantContext: null,
                    credentialMutation: CredentialMutation::keep(),
                ),
            );
            $this->fail('Expected target frozen after trusted ERL persisted.');
        } catch (ConnectorAccountTargetFrozenException) {
            // expected
        }
    }

    #[Test]
    public function credential_rotation_serializes_safely_with_confirm(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, 'CRED-RACE-SKU');
        $this->responder->registerProduct('CRED-RACE-SKU', 7001, 'simple');

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

        $ipcDir = sys_get_temp_dir().'/entity-trust-cred-race-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/EntityTrust/EntityTrustConcurrencyWorker.php');
        $env = $this->mysqlWorkerEnvironment();

        $credentialWorker = new Process([
            PHP_BINARY,
            $workerScript,
            'rotate-credentials-during-confirm',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], base_path(), $env);
        $credentialWorker->setTimeout(120);
        $credentialWorker->start();

        $confirmWorker = new Process([
            PHP_BINARY,
            $workerScript,
            'confirm-with-start-signal',
            $account->workspace_id,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            (string) $actor->id,
            $ipcDir,
        ], base_path(), $env);
        $confirmWorker->setTimeout(120);
        $confirmWorker->start();

        $confirmWorker->wait();
        $credentialWorker->wait();

        $this->assertSame(0, $confirmWorker->getExitCode(), $confirmWorker->getErrorOutput());
        $this->assertSame(0, $credentialWorker->getExitCode(), $credentialWorker->getErrorOutput());
        $this->assertSame('success', file_get_contents($ipcDir.'/confirm_result'));
        $this->assertSame('success', file_get_contents($ipcDir.'/credential_result'));

        $this->assertSame(
            1,
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count(),
        );

        $account->refresh();
        $this->assertTrue(AdobePaaSCredentialMapper::hasCompleteSet($account->credentials));
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
