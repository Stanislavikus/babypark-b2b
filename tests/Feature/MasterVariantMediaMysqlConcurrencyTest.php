<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantMedia;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterVariantMediaMysqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function mysql_service_and_database_guards_are_race_safe(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Variant Media concurrency proof requires MySQL.');
        }

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions($workspace->id, 'Variant media concurrency manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Variant media concurrency product',
            'is_active' => true,
        ]);

        $serviceVariant = $this->variant($product, 'VM-CONC-SERVICE');
        $directVariant = $this->variant($product, 'VM-CONC-DIRECT');
        $serviceAsset = $this->asset((string) $workspace->id, 'concurrency-service.jpg');
        $directAsset = $this->asset((string) $workspace->id, 'concurrency-direct.jpg');

        try {
            $serviceResults = $this->runWorkers(
                mode: 'service',
                workspaceId: (string) $workspace->id,
                productId: (string) $product->id,
                variantId: (string) $serviceVariant->id,
                assetId: (string) $serviceAsset->id,
                actorId: (string) $actor->id,
                sortOrders: [0, 1],
            );

            $this->assertSame(['success', 'success'], collect($serviceResults)->pluck('status')->sort()->values()->all());
            $this->assertSame(
                1,
                VariantMedia::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('variant_id', $serviceVariant->id)
                    ->where('media_asset_id', $serviceAsset->id)
                    ->whereNull('locale')
                    ->count(),
            );

            $directResults = $this->runWorkers(
                mode: 'direct',
                workspaceId: (string) $workspace->id,
                productId: (string) $product->id,
                variantId: (string) $directVariant->id,
                assetId: (string) $directAsset->id,
                actorId: (string) $actor->id,
                sortOrders: [0, 1],
            );

            $this->assertSame(['duplicate', 'inserted'], collect($directResults)->pluck('status')->sort()->values()->all());

            $duplicate = collect($directResults)->firstWhere('status', 'duplicate');
            $this->assertSame('23000', $duplicate['sqlstate'] ?? null);
            $this->assertSame(1062, $duplicate['driver_code'] ?? null);
            $this->assertSame(
                1,
                VariantMedia::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->id)
                    ->where('variant_id', $directVariant->id)
                    ->where('media_asset_id', $directAsset->id)
                    ->whereNull('locale')
                    ->count(),
            );
        } finally {
            $this->cleanupCommittedFixtures(
                workspaceId: (string) $workspace->id,
                productId: (int) $product->id,
                actorId: (int) $actor->id,
                membershipId: (string) $membership->id,
                roleId: (string) $role->id,
                assetIds: [(string) $serviceAsset->id, (string) $directAsset->id],
            );
        }
    }

    /** @param list<string> $assetIds */
    private function cleanupCommittedFixtures(
        string $workspaceId,
        int $productId,
        int $actorId,
        string $membershipId,
        string $roleId,
        array $assetIds,
    ): void {
        DB::transaction(function () use (
            $workspaceId,
            $productId,
            $actorId,
            $membershipId,
            $roleId,
            $assetIds,
        ): void {
            DB::table('products')
                ->where('workspace_id', $workspaceId)
                ->where('id', $productId)
                ->delete();

            DB::table('media_assets')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $assetIds)
                ->delete();

            DB::table('workspace_user_roles')
                ->where('workspace_id', $workspaceId)
                ->where('workspace_user_id', $membershipId)
                ->where('workspace_role_id', $roleId)
                ->delete();

            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspaceId)
                ->where('workspace_role_id', $roleId)
                ->delete();

            DB::table('workspace_roles')
                ->where('workspace_id', $workspaceId)
                ->where('id', $roleId)
                ->delete();

            DB::table('workspace_users')
                ->where('workspace_id', $workspaceId)
                ->where('id', $membershipId)
                ->delete();

            DB::table('users')
                ->where('id', $actorId)
                ->delete();
        });
    }

    /**
     * @param  list<int>  $sortOrders
     * @return list<array<string,mixed>>
     */
    private function runWorkers(
        string $mode,
        string $workspaceId,
        string $productId,
        string $variantId,
        string $assetId,
        string $actorId,
        array $sortOrders,
    ): array {
        $ipcDir = sys_get_temp_dir().'/variant-media-concurrency-'.uniqid('', true);
        File::ensureDirectoryExists($ipcDir);

        $worker = base_path('tests/Support/MasterVariantMediaConcurrencyWorker.php');
        $processes = [];

        try {
            foreach ($sortOrders as $sortOrder) {
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    $mode,
                    $workspaceId,
                    $productId,
                    $variantId,
                    $assetId,
                    $actorId,
                    (string) $sortOrder,
                    $ipcDir,
                ], base_path());

                $process->setTimeout(90);
                $process->start();

                $processes[] = [
                    'pid' => $process->getPid(),
                    'process' => $process,
                ];
            }

            $this->waitForReadyWorkers($ipcDir, count($processes));
            file_put_contents($ipcDir.'/go', '1');

            $results = [];
            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];
                $process->wait();

                $this->assertSame(
                    0,
                    $process->getExitCode(),
                    $process->getErrorOutput().$process->getOutput(),
                );

                $path = $ipcDir.'/'.$entry['pid'].'.result';
                $this->assertFileExists($path);
                $results[] = json_decode(
                    (string) file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            }

            return $results;
        } finally {
            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            File::deleteDirectory($ipcDir);
        }
    }

    private function waitForReadyWorkers(string $ipcDir, int $expected): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            if (count(glob($ipcDir.'/*.ready') ?: []) === $expected) {
                return;
            }

            usleep(25_000);
        }

        $this->fail('Timed out waiting for Variant Media concurrency workers.');
    }

    private function variant(Product $product, string $sku): ProductVariant
    {
        return ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $product->workspace_id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => $sku,
            'attributes' => [],
            'is_active' => true,
        ]);
    }

    private function asset(string $workspaceId, string $filename): MediaAsset
    {
        return MediaAsset::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceId,
            'parent_media_asset_id' => null,
            'asset_type' => MediaAssetType::Image,
            'source_url' => 'https://cdn.example.test/'.$filename,
            'diagnosis_status' => MediaDiagnosisStatus::Ready,
        ]);
    }
}
