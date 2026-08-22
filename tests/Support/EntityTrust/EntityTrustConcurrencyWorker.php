<?php

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

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? null;
$workspaceId = $argv[2] ?? null;
$accountId = $argv[3] ?? null;
$productId = $argv[4] ?? null;
$reviewToken = $argv[5] ?? null;
$actorId = $argv[6] ?? null;
$ipcDir = $argv[7] ?? null;
$newBaseUrl = $argv[8] ?? null;

if (! $mode || ! $workspaceId || ! $accountId || ! $productId || ! $reviewToken || ! $actorId || ! $ipcDir) {
    fwrite(STDERR, "Missing worker arguments.\n");
    exit(2);
}

$responder = new EntityTrustAdobeTransportResponder;
$responder->registerProduct('SHARED-RACE-SKU', 3001, 'simple');
$responder->registerProduct('SHARED-RACE-SKU-B', 3001, 'simple');
$responder->registerProduct('TARGET-RACE-SKU', 6001, 'simple');
$responder->registerProduct('CRED-RACE-SKU', 7001, 'simple');
app()->instance(ConnectorHttpTransport::class, new RecordingConnectorHttpTransport(
    fn ($request, $count) => $responder($request, $count),
));

$actor = User::query()->findOrFail($actorId);
$workspace = Workspace::query()->findOrFail($workspaceId);
$confirmation = app(AdobeProductEntityTrustConfirmationService::class);
$settings = app(ConnectorAccountSettingsService::class);

if ($mode === 'confirm-a' || $mode === 'confirm-b') {
    try {
        $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
        file_put_contents($ipcDir.'/winner', $mode === 'confirm-a' ? 'a' : 'b');
    } catch (EntityTrustException) {
        file_put_contents($ipcDir.'/loser', $mode === 'confirm-a' ? 'a' : 'b');
    }

    exit(0);
}

if ($mode === 'change-target-then-release') {
    DB::transaction(function () use ($workspaceId, $accountId, $ipcDir, $newBaseUrl): void {
        Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();

        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();

        file_put_contents($ipcDir.'/target_lock_acquired', '1');

        $deadline = time() + 30;
        while (time() < $deadline) {
            if (is_file($ipcDir.'/proceed_target_change')) {
                break;
            }

            usleep(50_000);
        }

        $account->base_url = $newBaseUrl ?? 'https://changed-target.example.com';
        $account->save();

        file_put_contents($ipcDir.'/target_changed', '1');
    });

    exit(0);
}

if ($mode === 'confirm-after-target-change') {
    $deadline = time() + 30;
    while (time() < $deadline) {
        if (is_file($ipcDir.'/target_changed')) {
            break;
        }

        usleep(50_000);
    }

    try {
        $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
        file_put_contents($ipcDir.'/confirm_result', 'success');
    } catch (EntityTrustException $exception) {
        file_put_contents($ipcDir.'/confirm_result', $exception->reason->value);
    }

    file_put_contents($ipcDir.'/confirm_finished', '1');
    exit(0);
}

if ($mode === 'hold-account-lock') {
    DB::transaction(function () use ($workspaceId, $accountId, $ipcDir): void {
        Workspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();

        ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();

        file_put_contents($ipcDir.'/account_lock_acquired', '1');

        $deadline = time() + 30;
        while (time() < $deadline) {
            if (is_file($ipcDir.'/release_account_lock')) {
                break;
            }

            usleep(50_000);
        }
    });

    file_put_contents($ipcDir.'/account_lock_released', '1');
    exit(0);
}

if ($mode === 'confirm-after-lock-release') {
    $deadline = time() + 30;
    while (time() < $deadline) {
        if (is_file($ipcDir.'/account_lock_acquired')) {
            break;
        }

        usleep(50_000);
    }

    file_put_contents($ipcDir.'/confirm_waiting_for_lock_release', '1');

    $deadline = time() + 30;
    while (time() < $deadline) {
        if (is_file($ipcDir.'/release_account_lock')) {
            break;
        }

        usleep(50_000);
    }

    try {
        $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
        file_put_contents($ipcDir.'/confirm_result', 'success');
    } catch (EntityTrustException $exception) {
        file_put_contents($ipcDir.'/confirm_result', $exception->reason->value);
    }

    file_put_contents($ipcDir.'/confirm_finished', '1');
    exit(0);
}

if ($mode === 'update-target-after-confirm') {
    $deadline = time() + 30;
    while (time() < $deadline) {
        if (is_file($ipcDir.'/confirm_finished')) {
            break;
        }

        usleep(50_000);
    }

    try {
        $settings->update(
            $actor,
            $workspace,
            $accountId,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: 'https://blocked-target.example.com',
                storeCode: 'default',
                tenantContext: null,
                credentialMutation: CredentialMutation::keep(),
            ),
        );
        file_put_contents($ipcDir.'/settings_result', 'success');
    } catch (ConnectorAccountTargetFrozenException) {
        file_put_contents($ipcDir.'/settings_result', 'target_frozen');
    }

    file_put_contents($ipcDir.'/settings_finished', '1');
    exit(0);
}

if ($mode === 'rotate-credentials-during-confirm') {
    $deadline = time() + 30;
    while (time() < $deadline) {
        if (is_file($ipcDir.'/confirm_started')) {
            break;
        }

        usleep(50_000);
    }

    try {
        $settings->update(
            $actor,
            $workspace,
            $accountId,
            UpdateConnectorAccountInput::adobePaas(
                baseUrl: ConnectorAccount::withoutWorkspaceScope()->findOrFail($accountId)->base_url,
                storeCode: ConnectorAccount::withoutWorkspaceScope()->findOrFail($accountId)->store_code,
                tenantContext: null,
                credentialMutation: CredentialMutation::replace(
                    new OAuth1Credentials('ck_race', 'cs_race', 'at_race', 'ts_race'),
                ),
            ),
        );
        file_put_contents($ipcDir.'/credential_result', 'success');
    } catch (Throwable $exception) {
        file_put_contents($ipcDir.'/credential_result', $exception::class);
    }

    file_put_contents($ipcDir.'/credential_finished', '1');
    exit(0);
}

if ($mode === 'confirm-with-start-signal') {
    file_put_contents($ipcDir.'/confirm_started', '1');

    try {
        $confirmation->confirm($actor, $workspace, $accountId, $productId, $reviewToken);
        file_put_contents($ipcDir.'/confirm_result', 'success');
    } catch (EntityTrustException $exception) {
        file_put_contents($ipcDir.'/confirm_result', $exception->reason->value);
    }

    file_put_contents($ipcDir.'/confirm_finished', '1');
    exit(0);
}

fwrite(STDERR, "Unknown worker mode: {$mode}\n");
exit(2);
