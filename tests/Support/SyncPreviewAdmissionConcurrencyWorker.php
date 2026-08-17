<?php

use App\Enums\SyncSemanticOperation;
use App\Models\ConnectorAccount;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Services\Sync\SyncPreviewAdmissionService;
use App\Support\Sync\Exceptions\SyncPreviewAdmissionException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$workspaceId = $argv[2] ?? null;
$connectorAccountId = $argv[3] ?? null;
$configurationId = $argv[4] ?? null;
$actorId = $argv[5] ?? null;
$ipcDir = $argv[6] ?? null;

if (! $mode || ! $workspaceId || ! $connectorAccountId || ! $configurationId || ! $actorId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($connectorAccountId);
$actor = User::query()->findOrFail($actorId);
$service = app(SyncPreviewAdmissionService::class);

if ($mode === 'hold-lock') {
    DB::transaction(function () use ($service, $actor, $account, $configurationId, $ipcDir): void {
        SyncConfiguration::withoutWorkspaceScope()
            ->where('id', $configurationId)
            ->lockForUpdate()
            ->first();

        file_put_contents($ipcDir.'/lock_acquired', '1');

        $deadline = time() + 30;
        while (time() < $deadline) {
            if (is_file($ipcDir.'/release_lock')) {
                break;
            }

            usleep(100_000);
        }

        $service->admit($actor, $account, $configurationId, SyncSemanticOperation::Export);
    });

    exit(0);
}

if ($mode === 'second-admit') {
    try {
        $service->admit($actor, $account, $configurationId, SyncSemanticOperation::Export);
        echo "unexpected success\n";
        exit(1);
    } catch (SyncPreviewAdmissionException $exception) {
        echo $exception->getMessage()."\n";
        exit(0);
    }
}

fwrite(STDERR, "Unknown mode: {$mode}\n");
exit(2);
