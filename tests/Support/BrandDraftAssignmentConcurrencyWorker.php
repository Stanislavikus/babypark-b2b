<?php

use App\Models\Brand;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\BrandManager;
use App\Services\Catalog\MasterProductDraftCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

match ($mode) {
    'deactivate' => runDeactivate(
        $argv[2] ?? '',
        $argv[3] ?? '',
        $argv[4] ?? '',
        $argv[5] ?? '',
    ),
    'create' => runCreate(
        $argv[2] ?? '',
        $argv[3] ?? '',
        $argv[4] ?? '',
    ),
    default => throw new InvalidArgumentException("Unknown worker mode: {$mode}"),
};

function waitForFile(string $path, int $seconds = 60): void
{
    $deadline = time() + $seconds;

    while (! file_exists($path) && time() < $deadline) {
        usleep(50_000);
    }

    if (! file_exists($path)) {
        throw new RuntimeException("Timed out waiting for {$path}");
    }
}

function runDeactivate(
    string $workspaceId,
    string $actorUserId,
    string $brandId,
    string $ipcDir,
): void {
    try {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $actor = User::query()->findOrFail($actorUserId);
        $brand = Brand::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->findOrFail($brandId);

        DB::beginTransaction();

        Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        touch($ipcDir.'/deactivate_workspace_locked');
        waitForFile($ipcDir.'/create_started');
        waitForFile($ipcDir.'/parent_release_deactivate');

        app(BrandManager::class)->update(
            $actor,
            $workspace,
            $brand,
            [
                'name' => $brand->name,
                'logo_media_asset_id' => $brand->logo_media_asset_id,
                'short_description' => $brand->short_description,
                'is_active' => false,
            ],
        );

        DB::commit();
        file_put_contents($ipcDir.'/deactivate_result', 'committed');
        exit(0);
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        file_put_contents($ipcDir.'/deactivate_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runCreate(string $workspaceId, string $brandId, string $ipcDir): void
{
    waitForFile($ipcDir.'/deactivate_workspace_locked');
    touch($ipcDir.'/create_started');

    try {
        $workspace = Workspace::query()->findOrFail($workspaceId);

        app(MasterProductDraftCreator::class)->create($workspace, [
            'name' => 'Concurrent inactive Brand product',
            'brand_id' => $brandId,
        ]);

        file_put_contents($ipcDir.'/create_result', 'committed');
        exit(1);
    } catch (InvalidArgumentException) {
        file_put_contents($ipcDir.'/create_result', 'rejected');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/create_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}
