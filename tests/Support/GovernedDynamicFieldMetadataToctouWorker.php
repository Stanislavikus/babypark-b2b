<?php

use App\Enums\AttributeStatus;
use App\Models\FieldDefinition;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$fieldDefinitionId = $argv[2] ?? null;
$ipcDir = $argv[3] ?? null;

if ($mode !== 'archive-definition-hold' || ! $fieldDefinitionId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

if (! is_dir($ipcDir) && ! mkdir($ipcDir, 0777, true) && ! is_dir($ipcDir)) {
    fwrite(STDERR, "Cannot create IPC dir {$ipcDir}\n");
    exit(2);
}

$changedFile = $ipcDir.'/metadata_changed_uncommitted';
$releaseFile = $ipcDir.'/release_lock';
$committedFile = $ipcDir.'/metadata_committed';
$errorFile = $ipcDir.'/metadata_error';

try {
    DB::transaction(function () use ($fieldDefinitionId, $changedFile, $releaseFile): void {
        $definition = FieldDefinition::withoutWorkspaceScope()
            ->whereKey($fieldDefinitionId)
            ->lockForUpdate()
            ->firstOrFail();

        $definition->status = AttributeStatus::Archived;
        $definition->save();

        file_put_contents($changedFile, '1');

        $deadline = microtime(true) + 30.0;

        while (! is_file($releaseFile) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if (! is_file($releaseFile)) {
            throw new RuntimeException('Timed out waiting for release_lock marker.');
        }
    });

    file_put_contents($committedFile, '1');
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
