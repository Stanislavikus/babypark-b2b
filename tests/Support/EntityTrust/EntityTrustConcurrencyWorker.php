<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$workspaceId = $argv[2] ?? null;
$accountId = $argv[3] ?? null;
$productId = $argv[4] ?? null;
$reviewToken = $argv[5] ?? null;
$actorId = $argv[6] ?? null;
$ipcDir = $argv[7] ?? null;

if (! $mode || ! $workspaceId || ! $accountId || ! $productId || ! $reviewToken || ! $actorId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$responder = new EntityTrustAdobeTransportResponder;
$responder->registerProduct('SHARED-RACE-SKU', 3001, 'simple');
$responder->registerProduct('SHARED-RACE-SKU-B', 3001, 'simple');
app()->instance(ConnectorHttpTransport::class, new RecordingConnectorHttpTransport(
    fn ($request, $count) => $responder($request, $count),
));

$actor = User::query()->findOrFail($actorId);
$workspace = Workspace::query()->findOrFail($workspaceId);
$confirmation = app(AdobeProductEntityTrustConfirmationService::class);

try {
    $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
    file_put_contents($ipcDir.'/winner', $mode === 'confirm-a' ? 'a' : 'b');
} catch (EntityTrustException) {
    file_put_contents($ipcDir.'/loser', $mode === 'confirm-a' ? 'a' : 'b');
}

exit(0);
