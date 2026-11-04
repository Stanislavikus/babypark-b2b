<?php

namespace Tests\Feature\Sync;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Filament\Pages\Sync\ManageSyncFieldMappings;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSource;
use App\Models\SyncConfiguration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\TestCase;

/**
 * Relocated from tests/Feature/Connectors to tests/Feature/Sync.
 *
 * GAP-025A contract: the merchant-facing "Available fields" summary belongs to
 * the Mapping surface, not the ConnectorAccount Overview.
 */
class ConnectorAccountDiscoveryOverviewTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    private const SENSITIVE_CANARY = 'DISCOVERY_SENSITIVE_CANARY_4B2B';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();

        $this->configureSyncSupportProfile([
            // Mapping page requires a SyncConfiguration + read-model projector.
            // The specific operation does not matter for Available Fields summary.
            // Keep consistent with other Mapping tests.
            [SyncDataDomain::Products, SyncSemanticOperation::Import],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        App::setLocale('uk');
        Config::set('connectors.discovery.manual_trigger_enabled', true);
    }

    #[Test]
    public function available_fields_summary_shows_never_checked_empty_state(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->mappingActorWithDiscoveryControl($workspace);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee(__('connectors.ui.sections.available_fields'))
            ->assertSee(__('connectors.ui.available_fields.never_checked'));
    }

    #[Test]
    public function available_fields_summary_shows_active_runtime_with_polling(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->mappingActorWithDiscoveryControl($workspace);

        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Running);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->fresh()->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee(__('connectors.ui.available_fields.refreshing'));

        $this->assertStringContainsString('wire:poll.5s="refreshDiscoveryState"', $component->html());
    }

    #[Test]
    public function available_fields_summary_shows_succeeded_state_with_snapshot_details(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->mappingActorWithDiscoveryControl($workspace);

        $account->update([
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now(),
        ]);

        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'finished_at' => now(),
        ]);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => 42,
            'canonical_hash' => hash('sha256', 'snapshot-a'),
        ]);
        $run->update(['snapshot_id' => $snapshot->id]);

        Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->fresh()->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee('42')
            ->assertSee(__('connectors.ui.available_fields.field_count'))
            ->assertSee(__('connectors.ui.available_fields.checked_at'));
    }

    #[Test]
    public function available_fields_summary_shows_failed_safe_error_only(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->mappingActorWithDiscoveryControl($workspace);

        $account->update(['last_discovery_at' => now()]);

        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Failed, [
            'finished_at' => now(),
            'user_message_key' => 'connectors.errors.discovery_failed',
            'technical_summary' => self::SENSITIVE_CANARY,
            'error_code' => 'discovery_vendor_timeout',
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->fresh()->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee(__('connectors.errors.discovery_failed', locale: 'uk'));

        $this->assertSensitiveFieldsAbsent($component);
    }

    #[Test]
    public function available_fields_summary_projects_field_count_from_last_success_after_failure(): void
    {
        [$workspace, $account, $configuration] = $this->fixture();
        $actor = $this->mappingActorWithDiscoveryControl($workspace);

        $account->update([
            'last_discovery_at' => now(),
            'last_successful_discovery_at' => now()->subHour(),
        ]);

        $successfulRun = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded, [
            'created_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);
        $successfulSnapshot = $this->createSnapshotForRun($successfulRun, [
            'field_count' => 17,
            'canonical_hash' => hash('sha256', self::SENSITIVE_CANARY),
        ]);
        $successfulRun->update(['snapshot_id' => $successfulSnapshot->id]);

        $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Failed, [
            'created_at' => now(),
            'finished_at' => now(),
            'user_message_key' => 'connectors.errors.discovery_failed',
            'technical_summary' => self::SENSITIVE_CANARY,
            'error_code' => 'discovery_vendor_timeout',
        ]);

        $component = Livewire::actingAs($actor)
            ->test(ManageSyncFieldMappings::class, [
                'account' => $account->fresh()->id,
                'configuration' => $configuration->id,
            ])
            ->assertSee('17')
            ->assertSee(__('connectors.ui.available_fields.field_count'))
            ->assertSee(__('connectors.errors.discovery_failed', locale: 'uk'));

        $this->assertSensitiveFieldsAbsent($component);
    }

    /**
     * @return array{0: Workspace, 1: ConnectorAccount, 2: SyncConfiguration}
     */
    private function fixture(): array
    {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace, ['auth_profile' => 'test_sync_support']);
        $configuration = $this->createProductsSyncConfiguration($account);

        return [$workspace, $account, $configuration];
    }

    private function mappingActorWithDiscoveryControl(Workspace $workspace): User
    {
        $actor = $this->createStaffUser(UserRole::Merchandiser);

        $this->grantExactWorkspacePermissions($workspace, $actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::RUN_CONNECTOR_DISCOVERY,
            WorkspacePermissions::VIEW_SYNC_MAPPINGS,
        ]);

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDiscoveryRun(
        ConnectorAccount $account,
        ConnectorDiscoveryRunStatus $status,
        array $overrides = [],
    ): ConnectorDiscoveryRun {
        $source = ConnectorSchemaSource::query()
            ->where('connector_definition_id', $account->connector_definition_id)
            ->where('code', 'live_account_attributes')
            ->firstOrFail();

        $attributes = array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $source->id,
            'trigger' => ConnectorDiscoveryRunTrigger::Manual,
            'initiated_by_user_id' => null,
            'status' => $status,
            'execution_attempts' => 1,
            'retry_until_at' => now()->addHour(),
            'next_attempt_at' => null,
            'started_at' => in_array($status, [ConnectorDiscoveryRunStatus::Running, ConnectorDiscoveryRunStatus::Succeeded, ConnectorDiscoveryRunStatus::Failed], true)
                ? now()
                : null,
            'finished_at' => in_array($status, [ConnectorDiscoveryRunStatus::Succeeded, ConnectorDiscoveryRunStatus::Failed, ConnectorDiscoveryRunStatus::Cancelled], true)
                ? now()
                : null,
            'duration_ms' => 1200,
            'user_message_key' => null,
            'technical_summary' => null,
            'error_code' => null,
            'snapshot_id' => null,
            'previous_snapshot_id' => null,
        ], $overrides);

        $explicitCreatedAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create($attributes);

        if ($explicitCreatedAt !== null) {
            $run->forceFill(['created_at' => $explicitCreatedAt])->saveQuietly();
        }

        return $run->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSnapshotForRun(ConnectorDiscoveryRun $run, array $overrides = []): ConnectorSchemaSnapshot
    {
        return ConnectorSchemaSnapshot::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $run->workspace_id,
            'connector_account_id' => $run->connector_account_id,
            'connector_schema_source_id' => $run->connector_schema_source_id,
            'discovery_run_id' => $run->id,
            'previous_snapshot_id' => null,
            'schema_version' => '1.0',
            'field_count' => 10,
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'captured_at' => now(),
        ], $overrides));
    }

    private function assertSensitiveFieldsAbsent(Testable $component): void
    {
        $surfaces = [
            $component->html(),
            json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            json_encode($component->effects, JSON_THROW_ON_ERROR),
        ];

        $forbidden = [
            self::SENSITIVE_CANARY,
            'canonical_hash',
            'technical_summary',
            'error_code',
            '/V1/products/attributes',
        ];

        foreach ($forbidden as $needle) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($needle, $surface, "Sensitive value [{$needle}] leaked.");
            }
        }
    }
}
