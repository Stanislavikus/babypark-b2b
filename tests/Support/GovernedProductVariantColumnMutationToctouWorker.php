<?php

use App\Enums\AttributeStatus;
use App\Models\FieldDefinition;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$primaryId = $argv[2] ?? null;
$secondaryId = $argv[3] ?? null;
$ipcDir = $argv[4] ?? null;

if (! $mode || ! $primaryId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

if (! is_dir($ipcDir) && ! mkdir($ipcDir, 0777, true) && ! is_dir($ipcDir)) {
    fwrite(STDERR, "Cannot create IPC dir {$ipcDir}\n");
    exit(2);
}

$releaseFile = $ipcDir.'/release_lock';
$errorFile = $ipcDir.'/worker_error';

try {
    match ($mode) {
        'archive-definition-hold' => archiveDefinitionHold($primaryId, $ipcDir, $releaseFile),
        'move-product-workspace-hold' => moveProductWorkspaceHold((int) $primaryId, $secondaryId, $ipcDir, $releaseFile),
        default => throw new RuntimeException("Unknown mode {$mode}."),
    };

    exit(0);
} catch (Throwable $exception) {
    file_put_contents(
        $errorFile,
        json_encode([
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR),
    );
    exit(1);
}

function archiveDefinitionHold(string $fieldDefinitionId, string $ipcDir, string $releaseFile): void
{
    DB::transaction(function () use ($fieldDefinitionId, $ipcDir, $releaseFile): void {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->whereKey($fieldDefinitionId)
            ->lockForUpdate()
            ->firstOrFail();

        $definition->status = AttributeStatus::Archived;
        $definition->save();

        file_put_contents($ipcDir.'/metadata_changed_uncommitted', '1');

        waitForRelease($releaseFile);
    });

    file_put_contents($ipcDir.'/metadata_committed', '1');
}

function moveProductWorkspaceHold(int $productId, ?string $workspaceId, string $ipcDir, string $releaseFile): void
{
    if ($workspaceId === null) {
        throw new RuntimeException('Missing workspace id for move-product-workspace-hold.');
    }

    DB::transaction(function () use ($productId, $workspaceId, $ipcDir, $releaseFile): void {
        $product = Product::withoutWorkspaceScope()
            ->whereKey($productId)
            ->lockForUpdate()
            ->firstOrFail();

        $product->workspace_id = $workspaceId;
        $product->save();

        file_put_contents($ipcDir.'/target_changed_uncommitted', '1');

        waitForRelease($releaseFile);
    });

    file_put_contents($ipcDir.'/target_committed', '1');
}

function waitForRelease(string $releaseFile): void
{
    $deadline = microtime(true) + 30.0;

    while (! is_file($releaseFile) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    if (! is_file($releaseFile)) {
        throw new RuntimeException('Timed out waiting for release_lock marker.');
    }
}
