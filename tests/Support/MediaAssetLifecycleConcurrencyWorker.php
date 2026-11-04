<?php

use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaAssetLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $workspaceId, $actorId, $assetId, $productId, $ipcDir] = $argv;

$waitForFile = static function (string $path, int $seconds = 60): void {
    $deadline = time() + $seconds;

    while (! file_exists($path) && time() < $deadline) {
        usleep(50_000);
    }

    if (! file_exists($path)) {
        throw new RuntimeException('Timed out waiting for '.$path);
    }
};

if ($mode === 'delete') {
    $workspace = Workspace::query()->findOrFail($workspaceId);
    $actor = User::query()->findOrFail($actorId);
    $asset = MediaAsset::withoutWorkspaceScope()
        ->where('workspace_id', $workspaceId)
        ->findOrFail($assetId);

    DB::beginTransaction();

    try {
        app(MediaAssetLifecycleService::class)->deleteUnusedOriginal($actor, $workspace, $asset);
        file_put_contents($ipcDir.'/delete_applied', '1');
        $waitForFile($ipcDir.'/parent_release_delete');
        DB::commit();
        file_put_contents($ipcDir.'/delete_result', 'committed');
        exit(0);
    } catch (Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        file_put_contents($ipcDir.'/delete_result', 'error:'.$e::class.':'.$e->getMessage());
        fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
        exit(2);
    }
}

if ($mode === 'attach') {
    file_put_contents($ipcDir.'/attach_before_insert', '1');

    try {
        DB::table('product_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'product_id' => (int) $productId,
            'media_asset_id' => $assetId,
            'role' => 'gallery',
            'sort_order' => 0,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        file_put_contents($ipcDir.'/attach_result', 'attached');
        exit(0);
    } catch (QueryException) {
        file_put_contents($ipcDir.'/attach_result', 'rejected');
        exit(0);
    } catch (Throwable $e) {
        file_put_contents($ipcDir.'/attach_result', 'error:'.$e::class.':'.$e->getMessage());
        fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
        exit(2);
    }
}

fwrite(STDERR, 'Unknown mode'.PHP_EOL);
exit(3);
