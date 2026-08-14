<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorDiscoveryRunTrigger;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectorAccountResource\Pages\ViewConnectorSchemaSnapshot;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\ConnectorSchemaFieldPresenter;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\EnablesConnectorSchemaDiscoveryCapability;
use Tests\TestCase;

class ConnectorAccountSnapshotFieldBrowserTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use EnablesConnectorSchemaDiscoveryCapability;
    use RefreshDatabase;

    private const SENSITIVE_CANARY = 'FIELD_BROWSER_SENSITIVE_CANARY_4B2C';

    private const PAYLOAD_CANARY = '{"options":["red","blue"],"secret":"leak"}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspacePermissionSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->enableSchemaDiscoveryCapability();
    }

    #[Test]
    public function snapshot_fields_render_default_columns(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            [
                'external_field_key' => 'color',
                'external_label' => 'Color',
                'normalized_data_type' => 'select',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'external_field_key' => 'description',
                'external_label' => 'Description',
                'normalized_data_type' => 'long_text',
                'is_required' => null,
                'sort_order' => 2,
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertCanSeeTableRecords(
                ConnectorSchemaSnapshotField::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->orderBy('sort_order')
                    ->get(),
            )
            ->assertSee('color')
            ->assertSee('Color')
            ->assertSee(ConnectorSchemaFieldPresenter::normalizedDataTypeLabel('select'))
            ->assertSee(ConnectorSchemaFieldPresenter::booleanLabel(true))
            ->assertSee(ConnectorSchemaFieldPresenter::booleanLabel(null))
            ->assertSee(__('connectors.ui.snapshot.fields.section_title'));
    }

    #[Test]
    public function empty_snapshot_shows_localized_empty_state(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSee(__('connectors.ui.snapshot.fields.empty_heading'))
            ->assertSee(__('connectors.ui.snapshot.fields.empty_description'));
    }

    #[Test]
    public function search_filters_by_external_field_key(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'alpha_key', 'external_label' => 'Alpha'],
            ['external_field_key' => 'beta_key', 'external_label' => 'Beta'],
        ]);

        $alpha = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', 'alpha_key')
            ->firstOrFail();
        $beta = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', 'beta_key')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->searchTable('alpha_key')
            ->assertCanSeeTableRecords([$alpha])
            ->assertCanNotSeeTableRecords([$beta]);
    }

    #[Test]
    public function search_filters_by_external_label(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'field_a', 'external_label' => 'Unique Label One'],
            ['external_field_key' => 'field_b', 'external_label' => 'Another Label'],
        ]);

        $matching = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', 'field_a')
            ->firstOrFail();
        $other = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', 'field_b')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->searchTable('Unique Label One')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[Test]
    public function normalized_type_filter_limits_results(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'text_field', 'normalized_data_type' => 'text'],
            ['external_field_key' => 'number_field', 'normalized_data_type' => 'number'],
        ]);

        $textField = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'text_field')
            ->firstOrFail();
        $numberField = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'number_field')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->filterTable('normalized_data_type', 'text')
            ->assertCanSeeTableRecords([$textField])
            ->assertCanNotSeeTableRecords([$numberField]);
    }

    #[Test]
    public function required_filter_limits_results(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'required_field', 'is_required' => true],
            ['external_field_key' => 'optional_field', 'is_required' => false],
            ['external_field_key' => 'unknown_required_field', 'is_required' => null],
        ]);

        $unknownField = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'unknown_required_field')
            ->firstOrFail();
        $requiredField = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'required_field')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->filterTable('is_required', 'unknown')
            ->assertCanSeeTableRecords([$unknownField])
            ->assertCanNotSeeTableRecords([$requiredField]);
    }

    #[Test]
    public function scope_filter_limits_results(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'global_field', 'external_scope' => 'global'],
            ['external_field_key' => 'store_field', 'external_scope' => 'store'],
            ['external_field_key' => 'unknown_scope_field', 'external_scope' => null],
        ]);

        $storeField = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'store_field')
            ->firstOrFail();
        $globalField = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'global_field')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->filterTable('external_scope', 'store')
            ->assertCanSeeTableRecords([$storeField])
            ->assertCanNotSeeTableRecords([$globalField]);
    }

    #[Test]
    public function historical_snapshot_shows_only_its_fields(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        $account = $this->createConnectorAccount();
        $olderRun = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $olderSnapshot = $this->createSnapshotForRun($olderRun, [
            'field_count' => 1,
        ]);
        $this->createField($olderSnapshot, [
            'external_field_key' => 'historical_only_field',
            'external_label' => 'Historical Only',
        ]);

        $newerRun = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $newerSnapshot = $this->createSnapshotForRun($newerRun, [
            'field_count' => 1,
            'previous_snapshot_id' => $olderSnapshot->id,
        ]);
        $this->createField($newerSnapshot, [
            'external_field_key' => 'latest_only_field',
            'external_label' => 'Latest Only',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $olderSnapshot->getKey(),
            ])
            ->assertSee('historical_only_field')
            ->assertSee('Historical Only')
            ->assertDontSee('latest_only_field');
    }

    #[Test]
    public function foreign_snapshot_fields_do_not_appear_in_table(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'own_field', 'external_label' => 'Own Field'],
        ]);

        $foreignAccount = $this->createConnectorAccount();
        $foreignRun = $this->createDiscoveryRun($foreignAccount, ConnectorDiscoveryRunStatus::Succeeded);
        $foreignSnapshot = $this->createSnapshotForRun($foreignRun);
        $this->createField($foreignSnapshot, [
            'external_field_key' => 'foreign_field_key',
            'external_label' => 'Foreign Field',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertSee('own_field')
            ->assertDontSee('foreign_field_key');
    }

    #[Test]
    public function table_requests_cannot_broaden_snapshot_boundary(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'scoped_field', 'external_label' => 'Scoped Field'],
        ]);

        $foreignAccount = $this->createConnectorAccount();
        $foreignRun = $this->createDiscoveryRun($foreignAccount, ConnectorDiscoveryRunStatus::Succeeded);
        $foreignSnapshot = $this->createSnapshotForRun($foreignRun);
        $this->createField($foreignSnapshot, [
            'external_field_key' => 'foreign_boundary_field',
            'external_label' => 'Foreign Boundary',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ]);

        $originalSnapshotId = $component->get('snapshotId');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $component->set('snapshotId', $foreignSnapshot->getKey());

        $this->assertSame($originalSnapshotId, $component->get('snapshotId'));
    }

    #[Test]
    public function merchandiser_can_browse_snapshot_fields(): void
    {
        $merchandiser = $this->createStaffUser(UserRole::Merchandiser);
        $this->grantConnectorDiscovery($this->defaultWorkspace(), $merchandiser);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            [
                'external_field_key' => 'merch_field',
                'external_label' => 'Merch Field',
                'normalized_data_type' => 'text',
                'is_required' => null,
            ],
        ]);

        $field = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'merch_field')
            ->firstOrFail();

        $component = Livewire::actingAs($merchandiser)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertCanSeeTableRecords([$field])
            ->assertSee('merch_field')
            ->assertSee(ConnectorSchemaFieldPresenter::booleanLabel(null));

        $component->callTableAction('view', $field)
            ->assertSee('merch_field')
            ->assertSee(ConnectorSchemaFieldPresenter::booleanLabel(null));

        $this->assertSensitiveFieldsAbsent($component);
    }

    #[Test]
    public function sensitive_field_payload_and_hash_do_not_leak(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            [
                'external_field_key' => 'safe_visible_field',
                'external_label' => 'Safe Visible',
                'canonical_hash' => self::SENSITIVE_CANARY,
                'normalized_payload' => json_decode(self::PAYLOAD_CANARY, false, 512, JSON_THROW_ON_ERROR),
            ],
        ]);

        $field = ConnectorSchemaSnapshotField::query()
            ->where('external_field_key', 'safe_visible_field')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertCanSeeTableRecords([$field]);

        $component->callTableAction('view', $field);

        $this->assertSensitiveFieldsAbsent($component);
    }

    #[Test]
    public function page_does_not_expose_diff_vocabulary(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'diff_guard_field'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ]);

        $forbidden = [
            'added_count',
            'changed_count',
            'removed_count',
            'unchanged_count',
            'Added',
            'Changed',
            'Removed',
            'Unchanged',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $component->html());
        }
    }

    #[Test]
    public function default_table_ordering_puts_null_sort_order_last_then_orders_by_field_key(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'field_zebra', 'sort_order' => 2],
            ['external_field_key' => 'field_alpha', 'sort_order' => null],
            ['external_field_key' => 'field_beta', 'sort_order' => 1],
        ]);

        $orderedRecords = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->whereIn('external_field_key', ['field_beta', 'field_zebra', 'field_alpha'])
            ->get()
            ->sortBy(fn (ConnectorSchemaSnapshotField $field): array => [
                $field->sort_order === null ? 1 : 0,
                $field->sort_order ?? PHP_INT_MAX,
                $field->external_field_key,
            ])
            ->values();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->assertCanSeeTableRecords($orderedRecords, inOrder: true);
    }

    #[Test]
    public function explicit_sort_by_external_field_key_overrides_default_ordering(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'field_zebra', 'sort_order' => 2],
            ['external_field_key' => 'field_alpha', 'sort_order' => null],
            ['external_field_key' => 'field_beta', 'sort_order' => 1],
        ]);

        $orderedRecords = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->whereIn('external_field_key', ['field_beta', 'field_zebra', 'field_alpha'])
            ->orderByDesc('external_field_key')
            ->get();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->sortTable('external_field_key', 'desc')
            ->assertCanSeeTableRecords($orderedRecords, inOrder: true);
    }

    #[Test]
    public function explicit_sort_by_normalized_data_type_overrides_default_ordering(): void
    {
        $admin = $this->createStaffUserWithConnectorManage(UserRole::Admin);
        [$account, $snapshot] = $this->createSnapshotWithFields([
            ['external_field_key' => 'field_text', 'normalized_data_type' => 'text', 'sort_order' => 10],
            ['external_field_key' => 'field_boolean', 'normalized_data_type' => 'boolean', 'sort_order' => 1],
            ['external_field_key' => 'field_number', 'normalized_data_type' => 'number', 'sort_order' => 5],
        ]);

        $orderedRecords = ConnectorSchemaSnapshotField::query()
            ->where('snapshot_id', $snapshot->id)
            ->whereIn('external_field_key', ['field_text', 'field_boolean', 'field_number'])
            ->orderBy('normalized_data_type')
            ->get();

        Livewire::actingAs($admin)
            ->test(ViewConnectorSchemaSnapshot::class, [
                'record' => $account->getKey(),
                'snapshot' => $snapshot->getKey(),
            ])
            ->sortTable('normalized_data_type', 'asc')
            ->assertCanSeeTableRecords($orderedRecords, inOrder: true);
    }

    #[Test]
    public function field_browser_localization_keys_exist_in_all_locales(): void
    {
        $locales = ['en', 'uk', 'ru'];
        $keys = [
            'connectors.ui.snapshot.fields.section_title',
            'connectors.ui.snapshot.fields.empty_heading',
            'connectors.ui.snapshot.fields.columns.field',
            'connectors.ui.snapshot.fields.filters.type',
            'connectors.ui.snapshot.fields.detail.title',
            'connectors.ui.snapshot.fields.boolean.unknown',
            'connectors.ui.snapshot.fields.type.text',
            'connectors.ui.snapshot.fields.scope.global',
            'connectors.ui.snapshot.fields.scope.unknown',
        ];

        foreach ($locales as $locale) {
            $translations = json_decode(
                file_get_contents(base_path("lang/{$locale}.json")),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$key} in {$locale}");
                $this->assertNotSame($key, __($key, locale: $locale));
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array{0: ConnectorAccount, 1: ConnectorSchemaSnapshot}
     */
    private function createSnapshotWithFields(array $fields): array
    {
        $account = $this->createConnectorAccount();
        $run = $this->createDiscoveryRun($account, ConnectorDiscoveryRunStatus::Succeeded);
        $snapshot = $this->createSnapshotForRun($run, [
            'field_count' => count($fields),
        ]);

        foreach ($fields as $index => $fieldOverrides) {
            $this->createField($snapshot, array_merge([
                'sort_order' => $index + 1,
                'normalized_data_type' => 'text',
                'external_label' => 'Label '.$index,
                'external_field_key' => 'field_'.$index,
            ], $fieldOverrides));
        }

        return [$account, $snapshot];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createField(ConnectorSchemaSnapshot $snapshot, array $overrides = []): ConnectorSchemaSnapshotField
    {
        return ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $snapshot->workspace_id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => 'field_'.Str::random(6),
            'external_label' => 'Field Label',
            'normalized_data_type' => 'text',
            'is_required' => false,
            'is_multi_value' => false,
            'is_localizable' => false,
            'external_scope' => 'global',
            'normalized_payload' => (object) [],
            'canonical_hash' => hash('sha256', Str::uuid()->toString()),
            'sort_order' => 1,
        ], $overrides));
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

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create(array_merge([
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
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1200,
            'user_message_key' => null,
            'technical_summary' => null,
            'error_code' => null,
            'snapshot_id' => null,
            'previous_snapshot_id' => null,
            'created_at' => now(),
        ], $overrides));
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
            'field_count' => 0,
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
            self::PAYLOAD_CANARY,
            'canonical_hash',
            'normalized_payload',
            'endpoint_path',
            'credentials',
            'settings',
            'added_count',
            'changed_count',
            'removed_count',
            'unchanged_count',
        ];

        foreach ($forbidden as $needle) {
            foreach ($surfaces as $surface) {
                $this->assertStringNotContainsString($needle, $surface, "Sensitive value [{$needle}] leaked.");
            }
        }
    }
}
