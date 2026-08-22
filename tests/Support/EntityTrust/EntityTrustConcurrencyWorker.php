<?php

/**
 * Child-process worker for Stage 3E-R2b-1 entity trust MySQL contention proofs.
 *
 * Modes:
 *   hold-boundary
 *   settings-target-update
 *   confirm-trust
 *   credential-rotate
 *   confirm-a / confirm-b (SKU race)
 */

use App\Models\ConnectorAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Connectors\ConnectorAccountSettingsService;
use App\Services\Connectors\UpdateConnectorAccountInput;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\Exceptions\ConnectorAccountTargetFrozenException;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$workspaceId = $argv[2] ?? '';
$accountId = $argv[3] ?? '';
$productId = $argv[4] ?? '';
$reviewToken = $argv[5] ?? '';
$actorId = $argv[6] ?? '';
$ipcDir = $argv[7] ?? '';
$newBaseUrl = $argv[8] ?? '';
$entityId = isset($argv[9]) && $argv[9] !== '' ? (int) $argv[9] : null;
$sku = $argv[10] ?? '';

if ($mode === '' || $workspaceId === '' || $accountId === '' || $actorId === '' || $ipcDir === '') {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

assertIpcDir($ipcDir);

$responder = new EntityTrustAdobeTransportResponder;
$responder->registerProduct('SHARED-RACE-SKU', 3001, 'simple');
$responder->registerProduct('SHARED-RACE-SKU-B', 3001, 'simple');
$responder->registerProduct('TARGET-RACE-SKU', 6001, 'simple');
$responder->registerProduct('CONFIRM-FIRST-SKU', 6101, 'simple');
$responder->registerProduct('CRED-RACE-SKU', 7001, 'simple');

if (is_string($sku) && $sku !== '' && $entityId !== null) {
    $responder->registerProduct($sku, $entityId, 'simple');
}

app()->instance(ConnectorHttpTransport::class, new RecordingConnectorHttpTransport(
    fn ($request, $count) => $responder($request, $count),
));

$actor = User::query()->findOrFail($actorId);
$workspace = Workspace::query()->findOrFail($workspaceId);
$confirmation = app(AdobeProductEntityTrustConfirmationService::class);
$settings = app(ConnectorAccountSettingsService::class);

match ($mode) {
    'hold-boundary' => runHoldBoundary($workspaceId, $accountId, $ipcDir),
    'settings-target-update' => runSettingsTargetUpdate(
        $actor,
        $workspace,
        $accountId,
        $ipcDir,
        $newBaseUrl !== '' ? $newBaseUrl : 'https://changed-target.example.com',
    ),
    'confirm-trust' => runConfirmTrust(
        $actor,
        $workspace,
        $accountId,
        $productId,
        $reviewToken,
        $ipcDir,
    ),
    'credential-rotate' => runCredentialRotate($actor, $workspace, $accountId, $ipcDir),
    'confirm-a', 'confirm-b' => runConfirmRace($confirmation, $actor, $workspace, $accountId, $productId, $reviewToken, $ipcDir, $mode),
    default => throw new InvalidArgumentException("Unknown worker mode: {$mode}"),
};

function assertIpcDir(string $ipcDir): void
{
    if ($ipcDir === '' || ! is_dir($ipcDir)) {
        fwrite(STDERR, "IPC directory does not exist: {$ipcDir}\n");
        exit(2);
    }
}

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

function runHoldBoundary(string $workspaceId, string $accountId, string $ipcDir): void
{
    DB::transaction(function () use ($workspaceId, $accountId, $ipcDir): void {
        Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();

        ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();

        file_put_contents($ipcDir.'/boundary_lock_acquired', '1');
        waitForFile($ipcDir.'/release_boundary');
    });

    file_put_contents($ipcDir.'/boundary_released', '1');
    exit(0);
}

function runSettingsTargetUpdate(
    User $actor,
    Workspace $workspace,
    string $accountId,
    string $ipcDir,
    string $newBaseUrl,
): void {
    $account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($accountId);

    file_put_contents($ipcDir.'/settings_entered', '1');

    try {
        $settings = app(ConnectorAccountSettingsService::class);
        $settings->update(
            $actor,
            $workspace,
            $accountId,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: $newBaseUrl,
                storeCode: (string) $account->store_code,
                tenantContext: $account->tenant_context,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
        file_put_contents($ipcDir.'/settings_result', 'success');
    } catch (ConnectorAccountTargetFrozenException) {
        file_put_contents($ipcDir.'/settings_result', 'target_frozen');
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/settings_result', 'error:'.$exception->getMessage());
        file_put_contents($ipcDir.'/settings_finished', '1');
        exit(1);
    }

    file_put_contents($ipcDir.'/settings_finished', '1');
    exit(0);
}

function runConfirmTrust(
    User $actor,
    Workspace $workspace,
    string $accountId,
    string $productId,
    string $reviewToken,
    string $ipcDir,
): void {
    file_put_contents($ipcDir.'/confirm_entered', '1');

    try {
        $confirmation = app(AdobeProductEntityTrustConfirmationService::class);
        $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
        file_put_contents($ipcDir.'/confirm_result', 'success');
    } catch (EntityTrustException $exception) {
        file_put_contents($ipcDir.'/confirm_result', $exception->reason->value);
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/confirm_result', 'error:'.$exception->getMessage());
        file_put_contents($ipcDir.'/confirm_finished', '1');
        exit(1);
    }

    file_put_contents($ipcDir.'/confirm_finished', '1');
    exit(0);
}

function runCredentialRotate(
    User $actor,
    Workspace $workspace,
    string $accountId,
    string $ipcDir,
): void {
    $account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($accountId);

    file_put_contents($ipcDir.'/credential_entered', '1');

    try {
        $settings = app(ConnectorAccountSettingsService::class);
        $settings->update(
            $actor,
            $workspace,
            $accountId,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: (string) $account->base_url,
                storeCode: (string) $account->store_code,
                tenantContext: $account->tenant_context,
                credentialMutation: CredentialMutation::replace(
                    new OAuth1Credentials('ck_race', 'cs_race', 'at_race', 'ts_race'),
                ),
            ),
        );
        file_put_contents($ipcDir.'/credential_result', 'success');
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/credential_result', 'error:'.$exception::class);
        file_put_contents($ipcDir.'/credential_finished', '1');
        exit(1);
    }

    file_put_contents($ipcDir.'/credential_finished', '1');
    exit(0);
}

function runConfirmRace(
    AdobeProductEntityTrustConfirmationService $confirmation,
    User $actor,
    Workspace $workspace,
    string $accountId,
    string $productId,
    string $reviewToken,
    string $ipcDir,
    string $mode,
): void {
    try {
        $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
        file_put_contents($ipcDir.'/winner', $mode === 'confirm-a' ? 'a' : 'b');
    } catch (EntityTrustException) {
        file_put_contents($ipcDir.'/loser', $mode === 'confirm-a' ? 'a' : 'b');
    }

    exit(0);
}
