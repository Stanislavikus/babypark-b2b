<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorConnectionCheckTrigger;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorAccount;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Support\Connectors\AdobePaaS\Command\AdobeProductWriteAccessClassification;
use App\Support\Connectors\AdobePaaS\Presentation\AdobeProductWritePauseProjector;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class AdobeProductWritePausePresentationTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        App::setLocale('uk');
    }

    #[Test]
    public function connected_overview_surfaces_only_proven_product_write_permission_denial(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->connectedAdobeAccount();

        $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_permission_denied',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 0,
                'write_access_classification' => AdobeProductWriteAccessClassification::PermissionDenied->value,
                'sensitive_canary' => 'RAW_MAGENTO_DENIAL_CANARY',
            ],
            at: '2026-09-12 01:00:00',
        );

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful()
            ->assertSee(__('connectors.ui.layer_a.status.connected'))
            ->assertSee(__('connectors.ui.layer_a.product_write_paused.title'))
            ->assertSee(__('connectors.ui.layer_a.product_write_paused.body'))
            ->assertSee(__('connectors.ui.layer_a.product_write_paused.remediation'))
            ->assertDontSee(__('connectors.ui.layer_a.next_step.preview'));

        $this->assertStringNotContainsString('RAW_MAGENTO_DENIAL_CANARY', $component->html());
        $this->assertSame(
            ConnectorAccountConnectionStatus::Connected,
            $account->fresh()->connection_status,
        );
    }

    #[Test]
    public function ambiguous_access_rejection_does_not_manufacture_paused_permission_state(): void
    {
        $account = $this->connectedAdobeAccount();

        $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_http_rejected_or_failed',
            outcome: SyncLiveOutcome::Ambiguous,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 1,
                'write_access_classification' => AdobeProductWriteAccessClassification::AccessRejectedUndetermined->value,
            ],
            at: '2026-09-12 01:00:00',
        );

        $this->assertFalse(
            app(AdobeProductWritePauseProjector::class)->isPausedByProvenPermissionDenial($account),
        );

        $messageOnlyAccount = $this->connectedAdobeAccount();
        $this->createLiveEvidence(
            $messageOnlyAccount,
            reasonCode: 'stock_write_permission_denied',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 0,
            ],
            at: '2026-09-12 01:01:00',
        );

        $this->assertFalse(
            app(AdobeProductWritePauseProjector::class)->isPausedByProvenPermissionDenial($messageOnlyAccount),
        );
    }

    #[Test]
    public function newer_verified_write_clears_older_permission_denial_while_unrelated_runs_do_not(): void
    {
        $account = $this->connectedAdobeAccount();

        $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_permission_denied',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 0,
                'write_access_classification' => AdobeProductWriteAccessClassification::PermissionDenied->value,
            ],
            at: '2026-09-12 01:00:00',
        );
        $this->createLiveEvidence(
            $account,
            reasonCode: 'mapping_blocked',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 0,
                'reconciliation_get_attempts' => 0,
            ],
            at: '2026-09-12 01:01:00',
            findingCode: 'mapping_blocked',
        );

        $projector = app(AdobeProductWritePauseProjector::class);
        $this->assertTrue($projector->isPausedByProvenPermissionDenial($account));

        $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_verified',
            outcome: SyncLiveOutcome::Synchronized,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 1,
            ],
            at: '2026-09-12 01:02:00',
        );

        $this->assertFalse($projector->isPausedByProvenPermissionDenial($account));
    }

    #[Test]
    public function same_second_contradictory_runs_fail_safe_instead_of_using_uuid_order(): void
    {
        foreach ([['verified', 'denied'], ['denied', 'verified']] as $order) {
            $account = $this->connectedAdobeAccount();

            foreach ($order as $signal) {
                $this->createLiveEvidence(
                    $account,
                    reasonCode: $signal === 'denied' ? 'stock_write_permission_denied' : 'stock_write_verified',
                    outcome: $signal === 'denied' ? SyncLiveOutcome::NotApplied : SyncLiveOutcome::Synchronized,
                    context: $signal === 'denied'
                        ? [
                            'consequential_write_attempts' => 1,
                            'reconciliation_get_attempts' => 0,
                            'write_access_classification' => AdobeProductWriteAccessClassification::PermissionDenied->value,
                        ]
                        : [
                            'consequential_write_attempts' => 1,
                            'reconciliation_get_attempts' => 1,
                        ],
                    at: '2026-09-12 01:03:00',
                );
            }

            $this->assertTrue(
                app(AdobeProductWritePauseProjector::class)->isPausedByProvenPermissionDenial($account),
                'Same-second contradictory evidence must fail safe regardless of UUID/insertion order.',
            );
        }
    }

    #[Test]
    public function later_verified_item_in_same_run_clears_earlier_denial_when_item_time_orders_them(): void
    {
        $account = $this->connectedAdobeAccount();
        $run = $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_permission_denied',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 0,
                'write_access_classification' => AdobeProductWriteAccessClassification::PermissionDenied->value,
            ],
            at: '2026-09-12 01:04:00',
        );

        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'P08-SKU-SECOND',
            'name' => 'P08 Product Second',
            'is_active' => true,
        ]);

        $this->travelTo('2026-09-12 01:05:00');
        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_run_id' => $run->getKey(),
            'product_id' => $product->getKey(),
            'outcome' => SyncLiveOutcome::Synchronized->value,
            'findings' => [[
                'code' => 'command_evidence',
                'subject' => $product->sku,
                'context' => [
                    'reason_code' => 'stock_write_verified',
                    'consequential_write_attempts' => 1,
                    'reconciliation_get_attempts' => 1,
                ],
            ]],
        ]);
        SyncRun::withoutWorkspaceScope()->whereKey($run->getKey())->update([
            'completed_at' => now()->addMinute(),
        ]);
        $this->travelBack();

        $this->assertFalse(
            app(AdobeProductWritePauseProjector::class)->isPausedByProvenPermissionDenial($account),
        );
    }

    #[Test]
    public function successful_credential_replacement_invalidates_older_write_denial_without_claiming_new_write(): void
    {
        $account = $this->connectedAdobeAccount();

        $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_permission_denied',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 0,
                'write_access_classification' => AdobeProductWriteAccessClassification::PermissionDenied->value,
            ],
            at: '2026-09-12 01:00:00',
        );

        $this->travelTo('2026-09-12 01:01:00');
        ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->getKey(),
            'trigger' => ConnectorConnectionCheckTrigger::CredentialsReplacement,
            'status' => ConnectorConnectionCheckStatus::Succeeded,
            'execution_attempts' => 1,
            'safe_message_parameters' => [],
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'duration_ms' => 1000,
        ]);
        $this->travelBack();

        $this->assertFalse(
            app(AdobeProductWritePauseProjector::class)->isPausedByProvenPermissionDenial($account),
        );
    }

    #[Test]
    public function same_second_credential_replacement_does_not_hide_indistinguishable_denial(): void
    {
        $account = $this->connectedAdobeAccount();

        $this->createLiveEvidence(
            $account,
            reasonCode: 'stock_write_permission_denied',
            outcome: SyncLiveOutcome::NotApplied,
            context: [
                'consequential_write_attempts' => 1,
                'reconciliation_get_attempts' => 0,
                'write_access_classification' => AdobeProductWriteAccessClassification::PermissionDenied->value,
            ],
            at: '2026-09-12 01:06:00',
        );

        $this->travelTo('2026-09-12 01:06:00');
        ConnectorConnectionCheck::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->getKey(),
            'trigger' => ConnectorConnectionCheckTrigger::CredentialsReplacement,
            'status' => ConnectorConnectionCheckStatus::Succeeded,
            'execution_attempts' => 1,
            'safe_message_parameters' => [],
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
        ]);
        $this->travelBack();

        $this->assertTrue(
            app(AdobeProductWritePauseProjector::class)->isPausedByProvenPermissionDenial($account),
        );
    }

    private function connectedAdobeAccount(): ConnectorAccount
    {
        return $this->createConnectorAccount(overrides: [
            'connection_status' => ConnectorAccountConnectionStatus::Connected,
            'last_successful_check_at' => now(),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function createLiveEvidence(
        ConnectorAccount $account,
        string $reasonCode,
        SyncLiveOutcome $outcome,
        array $context,
        string $at,
        string $findingCode = 'command_evidence',
    ): SyncRun {
        $configuration = SyncConfiguration::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->getKey())
            ->first();

        if ($configuration === null) {
            $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
                'workspace_id' => $account->workspace_id,
                'connector_account_id' => $account->getKey(),
                'data_domain' => SyncDataDomain::Products,
                'external_context' => [],
                'enabled_operations' => [SyncSemanticOperation::Export->value],
                'operational_state' => SyncConfigurationOperationalState::Enabled,
                'configuration_revision' => hash('sha256', 'p08-write-pause'),
            ]);
        }

        $product = Product::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->first();

        if ($product === null) {
            $product = Product::withoutWorkspaceScope()->create([
                'workspace_id' => $account->workspace_id,
                'onec_guid' => (string) Str::uuid(),
                'sku' => 'P08-SKU',
                'name' => 'P08 Product',
                'is_active' => true,
            ]);
        }

        $this->travelTo($at);

        $run = SyncRun::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_configuration_id' => $configuration->getKey(),
            'configuration_revision' => $configuration->configuration_revision,
            'mode' => SyncRunMode::Live,
            'semantic_operation' => SyncSemanticOperation::Export,
            'status' => SyncRunStatus::Completed,
            'configuration_snapshot' => ['selection' => ['mode' => 'all_products']],
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        SyncRunItem::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'sync_run_id' => $run->getKey(),
            'product_id' => $product->getKey(),
            'outcome' => $outcome->value,
            'findings' => [[
                'code' => $findingCode,
                'subject' => $product->sku,
                'context' => array_merge(['reason_code' => $reasonCode], $context),
            ]],
        ]);

        $this->travelBack();

        return $run;
    }
}
