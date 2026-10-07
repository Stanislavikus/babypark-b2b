<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\CategoryTreeMutationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

match ($mode) {
    'delete' => runDelete($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''),
    'assign' => runAssign($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
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

function runDelete(string $workspaceId, string $actorUserId, string $categoryId, string $ipcDir): void
{
    try {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $actor = User::query()->findOrFail($actorUserId);
        $category = Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->findOrFail((int) $categoryId);

        DB::beginTransaction();

        Workspace::query()
            ->whereKey($workspace->id)
            ->lockForUpdate()
            ->firstOrFail();

        Category::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereKey($category->id)
            ->lockForUpdate()
            ->firstOrFail();

        touch($ipcDir.'/delete_category_locked');
        waitForFile($ipcDir.'/assign_before_update');
        waitForFile($ipcDir.'/parent_release_delete');

        app(CategoryTreeMutationService::class)->deleteSingle(
            $actor,
            $workspace,
            $category,
            null,
            [
                'products_count' => 0,
                'children_count' => 0,
                'mappings_count' => 0,
            ],
        );

        DB::commit();
        file_put_contents($ipcDir.'/delete_result', 'committed');
        exit(0);
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        file_put_contents($ipcDir.'/delete_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}

function runAssign(string $productId, string $categoryId, string $ipcDir): void
{
    waitForFile($ipcDir.'/delete_category_locked');
    touch($ipcDir.'/assign_before_update');

    try {
        Product::withoutWorkspaceScope()
            ->whereKey((int) $productId)
            ->update(['category_id' => (int) $categoryId]);

        file_put_contents($ipcDir.'/assign_result', 'committed');
        exit(0);
    } catch (QueryException) {
        file_put_contents($ipcDir.'/assign_result', 'rejected');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/assign_result', 'error:'.$exception->getMessage());
        exit(1);
    }
}
