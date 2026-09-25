<?php

/**
 * GAP-024 visual-test fixture bootstrap (idempotent).
 *
 * Shared by Filament 4 (eb23a62 worktree) and Filament 5 (PR4) supplemental captures.
 * Visual-test setup only — not application/runtime architecture.
 */
$root = getenv('GAP024_APP_ROOT') ?: dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDefinition;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobePaaSCredentialMapper;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

$workspace = Workspace::query()->where('is_default', true)->firstOrFail();
$definition = ConnectorDefinition::query()->where('code', 'adobe_commerce')->firstOrFail();

$fixtureName = 'GAP-024 Visual Fixture';
$fixtureStore = 'visual-fixture-store';
$deterministicImage = 'https://picsum.photos/seed/gap024-visual-fixture/400/400';

// Deterministic product image for lightbox / catalogue surfaces (product #1).
Product::query()->where('sku', 'BP-00001')->update([
    'images' => [$deterministicImage],
]);

// Remove prior visual-test connector accounts with different names to avoid list noise.
ConnectorAccount::withoutWorkspaceScope()
    ->where('workspace_id', $workspace->id)
    ->where('name', '!=', $fixtureName)
    ->whereIn('name', ['Visual Baseline Adobe', 'GAP-024 Visual Fixture'])
    ->each(function (ConnectorAccount $account): void {
        ConnectorConnectionCheck::withoutWorkspaceScope()
            ->where('connector_account_id', $account->id)
            ->delete();
        $account->delete();
    });

$account = ConnectorAccount::withoutWorkspaceScope()->updateOrCreate(
    ['workspace_id' => $workspace->id, 'name' => $fixtureName],
    [
        'id' => ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('name', $fixtureName)
            ->value('id') ?? (string) Str::uuid(),
        'connector_definition_id' => $definition->id,
        'auth_profile' => 'adobe_commerce_paas_oauth1_integration',
        'base_url' => 'https://visual-fixture.example.com',
        'store_code' => $fixtureStore,
        'tenant_context' => 'default',
        'is_enabled' => true,
        'settings' => [],
        'credentials' => AdobePaaSCredentialMapper::toStorageArray(
            new OAuth1Credentials('ck_gap024_visual', 'cs_gap024_visual', 'at_gap024_visual', 'ts_gap024_visual'),
        ),
        'connection_status' => ConnectorAccountConnectionStatus::Untested,
    ],
);

ConnectorConnectionCheck::withoutWorkspaceScope()
    ->where('connector_account_id', $account->id)
    ->delete();

$fixedChecks = [
    [
        'status' => ConnectorConnectionCheckStatus::Succeeded,
        'finished_at' => now()->subMinutes(30),
        'started_at' => now()->subMinutes(30)->subSeconds(2),
        'duration_ms' => 2100,
    ],
    [
        'status' => ConnectorConnectionCheckStatus::Failed,
        'finished_at' => now()->subMinutes(60),
        'started_at' => now()->subMinutes(60)->subSeconds(3),
        'duration_ms' => 3200,
        'cause_category' => ConnectorErrorCause::Authorization,
        'actionability' => ConnectorErrorActionability::UserActionRequired,
        'user_message_key' => 'connectors.errors.insufficient_permissions',
    ],
    [
        'status' => ConnectorConnectionCheckStatus::Queued,
        'finished_at' => null,
        'started_at' => null,
        'duration_ms' => null,
    ],
];

foreach ($fixedChecks as $check) {
    ConnectorConnectionCheck::withoutWorkspaceScope()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'connector_account_id' => $account->id,
        'trigger' => ConnectorConnectionCheckTrigger::Manual,
        'initiated_by_user_id' => null,
        'status' => $check['status'],
        'execution_attempts' => 0,
        'retry_until_at' => now()->addMinutes(15),
        'next_attempt_at' => null,
        'cause_category' => $check['cause_category'] ?? null,
        'actionability' => $check['actionability'] ?? null,
        'error_code' => null,
        'http_status' => null,
        'user_message_key' => $check['user_message_key'] ?? null,
        'safe_message_parameters' => null,
        'technical_summary' => null,
        'vendor_request_id' => null,
        'started_at' => $check['started_at'],
        'finished_at' => $check['finished_at'],
        'duration_ms' => $check['duration_ms'],
    ]);
}

$priceListId = PriceList::query()->where('is_default', false)->orderBy('name')->value('id')
    ?? PriceList::query()->orderBy('name')->value('id');

// Clear cabinet session cart for deterministic s15/s20 states.
if (session()->isStarted()) {
    session()->forget('b2b_cart');
}

echo json_encode([
    'connectorAccountId' => $account->id,
    'connectorAccountName' => $fixtureName,
    'connectorStoreCode' => $fixtureStore,
    'productSku' => 'BP-00001',
    'deterministicImage' => $deterministicImage,
    'priceListId' => (string) $priceListId,
], JSON_THROW_ON_ERROR);
