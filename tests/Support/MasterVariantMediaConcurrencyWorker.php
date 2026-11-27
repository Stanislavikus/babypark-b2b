<?php

use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\VariantMediaMutationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $workspaceId, $productId, $variantId, $assetId, $actorId, $sortOrder, $ipcDir] = $argv;

$pid = getmypid();
$ready = $ipcDir.'/'.$pid.'.ready';
$result = $ipcDir.'/'.$pid.'.result';

file_put_contents($ready, 'ready');

$deadline = microtime(true) + 60;
while (! is_file($ipcDir.'/go') && microtime(true) < $deadline) {
    usleep(25_000);
}

if (! is_file($ipcDir.'/go')) {
    file_put_contents($result, json_encode(['status' => 'timeout'], JSON_THROW_ON_ERROR));
    exit(2);
}

try {
    if ($mode === 'service') {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $product = Product::withoutWorkspaceScope()->findOrFail((int) $productId);
        $variant = ProductVariant::withoutWorkspaceScope()->findOrFail((int) $variantId);
        $asset = MediaAsset::withoutWorkspaceScope()->findOrFail($assetId);
        $actor = User::query()->findOrFail((int) $actorId);

        app(VariantMediaMutationService::class)->assign(
            $actor,
            $workspace,
            $product,
            [$variant->id],
            [$asset->id],
        );

        file_put_contents($result, json_encode(['status' => 'success'], JSON_THROW_ON_ERROR));
        exit(0);
    }

    if ($mode === 'direct') {
        DB::table('variant_media')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'variant_id' => (int) $variantId,
            'media_asset_id' => $assetId,
            'role' => 'gallery',
            'sort_order' => (int) $sortOrder,
            'locale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        file_put_contents($result, json_encode(['status' => 'inserted'], JSON_THROW_ON_ERROR));
        exit(0);
    }

    throw new RuntimeException('Unknown Variant Media concurrency worker mode.');
} catch (QueryException $exception) {
    $sqlState = (string) ($exception->errorInfo[0] ?? '');
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);

    if ($mode === 'direct' && $sqlState === '23000' && $driverCode === 1062) {
        file_put_contents($result, json_encode([
            'status' => 'duplicate',
            'sqlstate' => $sqlState,
            'driver_code' => $driverCode,
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    file_put_contents($result, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
} catch (Throwable $exception) {
    file_put_contents($result, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
