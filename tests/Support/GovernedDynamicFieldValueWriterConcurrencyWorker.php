<?php

use App\Enums\FieldObjectType;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$workspaceId = $argv[2] ?? null;
$targetType = $argv[3] ?? null;
$targetId = $argv[4] ?? null;
$fieldBindingId = $argv[5] ?? null;
$payload = $argv[6] ?? null;
$locale = $argv[7] ?? null;
$ipcDir = $argv[8] ?? null;

if (! $mode || ! $workspaceId || ! $targetType || ! $targetId || ! $fieldBindingId || $payload === null || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

if (! is_dir($ipcDir) && ! mkdir($ipcDir, 0777, true) && ! is_dir($ipcDir)) {
    fwrite(STDERR, "Cannot create IPC dir {$ipcDir}\n");
    exit(2);
}

$writer = app(GovernedDynamicFieldValueWriter::class);

$objectType = FieldObjectType::from($targetType);

try {
    if ($mode === 'set') {
        $result = $writer->set($workspaceId, $objectType, (int) $targetId, $fieldBindingId, $payload, $locale);
        file_put_contents(
            $ipcDir.'/'.getmypid().'.result',
            json_encode(['ok' => true, 'status' => $result->status->value], JSON_THROW_ON_ERROR),
        );
        exit(0);
    }

    if ($mode === 'clear') {
        $result = $writer->clear($workspaceId, $objectType, (int) $targetId, $fieldBindingId, $locale);
        file_put_contents(
            $ipcDir.'/'.getmypid().'.result',
            json_encode(['ok' => true, 'status' => $result->status->value], JSON_THROW_ON_ERROR),
        );
        exit(0);
    }

    fwrite(STDERR, "Unknown mode {$mode}\n");
    exit(2);
} catch (Throwable $e) {
    file_put_contents(
        $ipcDir.'/'.getmypid().'.result',
        json_encode([
            'ok' => false,
            'class' => $e::class,
            'message' => $e->getMessage(),
        ], JSON_THROW_ON_ERROR),
    );
    exit(1);
}
