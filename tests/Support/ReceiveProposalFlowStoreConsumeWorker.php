<?php

use App\Enums\FieldObjectType;
use App\Services\Sync\Receive\ReceiveProposalFlowStore;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['cache.default' => 'file']);

$flowId = $argv[1] ?? null;
$actorUserId = $argv[2] ?? null;
$workspaceId = $argv[3] ?? null;
$connectorAccountId = $argv[4] ?? null;
$syncConfigurationId = $argv[5] ?? null;
$targetType = $argv[6] ?? null;
$targetId = $argv[7] ?? null;
$ipcDir = $argv[8] ?? null;

if (
    ! is_string($flowId)
    || ! is_string($actorUserId)
    || ! is_string($workspaceId)
    || ! is_string($connectorAccountId)
    || ! is_string($syncConfigurationId)
    || ! is_string($targetType)
    || ! is_string($targetId)
    || ! is_string($ipcDir)
) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$binding = new ReceiveProposalFlowBinding(
    actorUserId: $actorUserId,
    workspaceId: $workspaceId,
    connectorAccountId: $connectorAccountId,
    syncConfigurationId: $syncConfigurationId,
    targetType: FieldObjectType::from($targetType),
    targetId: $targetId,
);

$resultFile = $ipcDir.'/'.getmypid().'.result';
file_put_contents($ipcDir.'/'.getmypid().'.ready', '1');

$deadline = time() + 30;
while (time() < $deadline) {
    if (is_file($ipcDir.'/go')) {
        break;
    }

    usleep(50_000);
}

$proposal = app(ReceiveProposalFlowStore::class)->consume($flowId, $binding);

file_put_contents($resultFile, json_encode([
    'consumed' => $proposal !== null,
], JSON_THROW_ON_ERROR));
