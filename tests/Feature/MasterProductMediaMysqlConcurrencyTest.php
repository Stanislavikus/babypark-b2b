<?php

namespace Tests\Feature;

use App\Enums\MediaAssetType;
use App\Enums\MediaDiagnosisStatus;
use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterProductMediaMysqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use InteractsWithWorkspaceRbac;

    #[Test]
    public function two_concurrent_writers_append_distinct_originals_with_one_primary_and_ordered_projection(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Product Media concurrency proof requires MySQL.');
        }

        $this->seed(WorkspaceSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $workspace = $this->defaultWorkspace();
        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);
        $membership = $this->makeWorkspaceMembership($workspace, $actor);
        $role = $this->createRoleWithPermissions($workspace->id, 'Product media concurrency manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => null,
            'sku' => null,
            'name' => 'Concurrent Product Media',
            'images' => [],
            'is_active' => true,
        ]);
        $first = $this->asset((string) $workspace->id, 'concurrent-first.jpg');
        $second = $this->asset((string) $workspace->id, 'concurrent-second.jpg');

        try {
            $results = $this->runWorkers(
                workspaceId: (string) $workspace->id,
                productId: (string) $product->id,
                assetIds: [(string) $first->id, (string) $second->id],
                actorId: (string) $actor->id,
            );

            $this->assertSame(['success', 'success'], collect($results)->pluck('status')->sort()->values()->all());

            $gallery = ProductMedia::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('product_id', $product->id)
                ->whereNull('locale')
                ->orderBy('sort_order')
                ->get();

            $this->assertCount(2, $gallery);
            $this->assertEqualsCanonicalizing(
                [(string) $first->id, (string) $second->id],
                $gallery->pluck('media_asset_id')->map('strval')->all(),
            );
            $this->assertSame([0, 1], $gallery->pluck('sort_order')->map(fn ($n): int => (int) $n)->all());
            $this->assertSame([MediaRole::Primary, MediaRole::Gallery], $gallery->pluck('role')->all());

            $urls = [
                (string) $first->id => (string) $first->source_url,
                (string) $second->id => (string) $second->source_url,
            ];
            $this->assertSame(
                $gallery->map(fn (ProductMedia $item): string => $urls[(string) $item->media_asset_id])->all(),
                $product->fresh()->images,
                'The legacy projection must follow the transactionally committed canonical gallery order.',
            );
            $this->assertSame(2, MediaAsset::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)->count());
        } finally {
            $this->cleanupCommittedFixtures(
                workspaceId: (string) $workspace->id,
                productId: (int) $product->id,
                actorId: (int) $actor->id,
                membershipId: (string) $membership->id,
                roleId: (string) $role->id,
                assetIds: [(string) $first->id, (string) $second->id],
            );
        }
    }

    /**
     * @param  list<string>  $assetIds
     * @return list<array<string, mixed>>
     */
    private function runWorkers(string $workspaceId, string $productId, array $assetIds, string $actorId): array
    {
        $ipcDir = sys_get_temp_dir().'/product-media-concurrency-'.uniqid('', true);
        File::ensureDirectoryExists($ipcDir);
        $worker = base_path('tests/Support/MasterProductMediaConcurrencyWorker.php');
        $processes = [];

        try {
            foreach ($assetIds as $assetId) {
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    $workspaceId,
                    $productId,
                    $assetId,
                    $actorId,
                    $ipcDir,
                ], base_path());

                $process->setTimeout(90);
                $process->start();
                $processes[] = [
                    'pid' => $process->getPid(),
                    'process' => $process,
                ];
            }

            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline && count(glob($ipcDir.'/*.ready') ?: []) !== count($processes)) {
                usleep(25_000);
            }
            $this->assertCount(count($processes), glob($ipcDir.'/*.ready') ?: [],
                'Timed out waiting for independent MySQL concurrency workers.');

            file_put_contents($ipcDir.'/go', '1');

            $results = [];
            foreach ($processes as $entry) {
                /** @var Process $process */
                $process = $entry['process'];
                $process->wait();

                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

                $resultPath = $ipcDir.'/'.$entry['pid'].'.result';
                $this->assertFileExists($resultPath);
                $results[] = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
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

    /** @param list<string> $assetIds */
    private function cleanupCommittedFixtures(
        string $workspaceId,
        int $productId,
        int $actorId,
        string $membershipId,
        string $roleId,
        array $assetIds,
    ): void {
        DB::transaction(function () use ($workspaceId, $productId, $actorId, $membershipId, $roleId, $assetIds): void {
            DB::table('products')->where('workspace_id', $workspaceId)->where('id', $productId)->delete();
            DB::table('media_assets')->where('workspace_id', $workspaceId)->whereIn('id', $assetIds)->delete();
            DB::table('workspace_user_roles')
                ->where('workspace_id', $workspaceId)
                ->where('workspace_user_id', $membershipId)
                ->where('workspace_role_id', $roleId)
                ->delete();
            DB::table('workspace_role_permissions')
                ->where('workspace_id', $workspaceId)->where('workspace_role_id', $roleId)->delete();
            DB::table('workspace_roles')->where('workspace_id', $workspaceId)->where('id', $roleId)->delete();
            DB::table('workspace_users')->where('workspace_id', $workspaceId)->where('id', $membershipId)->delete();
            DB::table('users')->where('id', $actorId)->delete();
        });
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
