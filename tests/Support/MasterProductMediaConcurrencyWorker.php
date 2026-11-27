<?php

use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\ProductMediaMutationService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $workspaceId, $productId, $assetId, $actorId, $ipcDir] = $argv;

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
    $workspace = Workspace::query()->findOrFail($workspaceId);
    $product = Product::withoutWorkspaceScope()->findOrFail((int) $productId);
    $actor = User::query()->findOrFail((int) $actorId);

    app(ProductMediaMutationService::class)->addExistingImages(
        $actor,
        $workspace,
        $product,
        [$assetId],
    );

    file_put_contents($result, json_encode(['status' => 'success'], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
