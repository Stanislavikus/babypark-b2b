<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\OriginalImageIngestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $workspaceId, $actorId, $sourcePath, $ipcDir] = $argv;

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
    $actor = User::query()->findOrFail((int) $actorId);
    $file = new UploadedFile(
        $sourcePath,
        'concurrent-original.png',
        'image/png',
        null,
        true,
    );

    $asset = app(OriginalImageIngestService::class)
        ->ingestStandalone($actor, $workspace, $file);

    file_put_contents($result, json_encode([
        'status' => 'success',
        'asset_id' => (string) $asset->id,
        'storage_path' => $asset->storage_path,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($result, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
