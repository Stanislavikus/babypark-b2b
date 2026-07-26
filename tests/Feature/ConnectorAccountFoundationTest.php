<?php

namespace Tests\Feature;

use App\Enums\ConnectorAccountConnectionStatus;
use App\Enums\ConnectorConnectionCheckStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorSchemaDiffItemChangeType;
use App\Models\ConnectorAccount;
use App\Models\ConnectorConnectionCheck;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaDiff;
use App\Models\ConnectorSchemaDiffItem;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceContext;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorAccountFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function migration_creates_all_seven_tables_with_expected_keys_and_indexes(): void
    {
        $tables = [
            'connector_accounts',
            'connector_connection_checks',
            'connector_discovery_runs',
            'connector_schema_snapshots',
            'connector_schema_snapshot_fields',
            'connector_schema_diffs',
            'connector_schema_diff_items',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $workspaceIdIdUniques = [
            'connector_accounts' => 'ca_ws_id_unique',
            'connector_discovery_runs' => 'cdr_ws_id_unique',
            'connector_schema_snapshots' => 'css_ws_id_unique',
            'connector_schema_snapshot_fields' => 'cssf_ws_id_unique',
            'connector_schema_diffs' => 'csd_ws_id_unique',
        ];

        foreach ($workspaceIdIdUniques as $table => $index) {
            $this->assertTrue($this->indexExists($table, $index), "Missing unique index {$index}");
        }

        $this->assertTrue($this->indexExists('connector_accounts', 'ca_ws_def_name_unique'));
        $this->assertTrue($this->indexExists('connector_connection_checks', 'ccc_account_created_idx'));
        $this->assertTrue($this->indexExists('connector_discovery_runs', 'cdr_account_created_idx'));
        $this->assertTrue($this->indexExists('connector_schema_snapshots', 'css_account_source_created_idx'));
        $this->assertTrue($this->indexExists('connector_schema_diffs', 'csd_to_snapshot_unique'));
        $this->assertTrue(Schema::hasColumn('connector_accounts', 'active_name_uniqueness_key'));
    }

    #[Test]
    public function migration_rolls_back_cleanly(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2]);

        foreach ([
            'connector_schema_diff_items',
            'connector_schema_diffs',
            'connector_schema_snapshot_fields',
            'connector_schema_snapshots',
            'connector_discovery_runs',
            'connector_connection_checks',
            'connector_accounts',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table still exists after rollback: {$table}");
        }

        $this->artisan('migrate');
    }

    #[Test]
    #[DataProvider('compositeForeignKeyEdges')]
    public function composite_foreign_keys_reject_cross_workspace_parent_reference(
        string $childTable,
        array $childColumns,
        string $parentTable,
        callable $parentFactory,
        callable $childPayloadFactory,
    ): void {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Other', 'is_default' => false]);

        $parent = $parentFactory($workspaceA);

        $payload = array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceB->id,
            'created_at' => now(),
        ], $childPayloadFactory($parent, $workspaceA));

        $this->expectException(QueryException::class);

        DB::table($childTable)->insert($payload);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string, 3: callable, 4: callable}>
     */
    public static function compositeForeignKeyEdges(): array
    {
        return [
            'connection_checks.account' => [
                'connector_connection_checks',
                ['connector_account_id'],
                'connector_accounts',
                fn (Workspace $workspace) => ConnectorAccount::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'connector_definition_id' => ConnectorDefinition::query()->firstOrFail()->id,
                    'name' => 'Account A',
                    'auth_profile' => 'test',
                    'credentials' => ['token' => 'x'],
                ]),
                fn (ConnectorAccount $parent) => [
                    'connector_account_id' => $parent->id,
                    'trigger' => 'manual',
                    'status' => 'succeeded',
                    'started_at' => now(),
                ],
            ],
            'discovery_runs.account' => [
                'connector_discovery_runs',
                ['connector_account_id'],
                'connector_accounts',
                fn (Workspace $workspace) => ConnectorAccount::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'connector_definition_id' => ConnectorDefinition::query()->firstOrFail()->id,
                    'name' => 'Run Account A',
                    'auth_profile' => 'test',
                    'credentials' => ['token' => 'x'],
                ]),
                fn (ConnectorAccount $parent) => [
                    'connector_account_id' => $parent->id,
                    'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
                    'trigger' => 'manual',
                    'status' => 'queued',
                ],
            ],
            'discovery_runs.snapshot' => [
                'connector_discovery_runs',
                ['snapshot_id'],
                'connector_schema_snapshots',
                fn (Workspace $workspace) => self::makeSnapshot($workspace),
                fn (ConnectorSchemaSnapshot $parent) => [
                    'connector_account_id' => $parent->connector_account_id,
                    'connector_schema_source_id' => $parent->connector_schema_source_id,
                    'snapshot_id' => $parent->id,
                    'trigger' => 'manual',
                    'status' => 'succeeded',
                ],
            ],
            'discovery_runs.previous_snapshot' => [
                'connector_discovery_runs',
                ['previous_snapshot_id'],
                'connector_schema_snapshots',
                fn (Workspace $workspace) => self::makeSnapshot($workspace),
                fn (ConnectorSchemaSnapshot $parent) => [
                    'connector_account_id' => $parent->connector_account_id,
                    'connector_schema_source_id' => $parent->connector_schema_source_id,
                    'previous_snapshot_id' => $parent->id,
                    'trigger' => 'manual',
                    'status' => 'succeeded',
                ],
            ],
            'snapshots.account' => [
                'connector_schema_snapshots',
                ['connector_account_id'],
                'connector_accounts',
                fn (Workspace $workspace) => ConnectorAccount::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'connector_definition_id' => ConnectorDefinition::query()->firstOrFail()->id,
                    'name' => 'Snapshot Account A',
                    'auth_profile' => 'test',
                    'credentials' => ['token' => 'x'],
                ]),
                fn (ConnectorAccount $parent) => self::snapshotPayloadForAccount($parent),
            ],
            'snapshots.discovery_run' => [
                'connector_schema_snapshots',
                ['discovery_run_id'],
                'connector_discovery_runs',
                fn (Workspace $workspace) => self::makeDiscoveryRun($workspace),
                fn (ConnectorDiscoveryRun $parent) => [
                    'connector_account_id' => $parent->connector_account_id,
                    'connector_schema_source_id' => $parent->connector_schema_source_id,
                    'discovery_run_id' => $parent->id,
                    'field_count' => 1,
                    'canonical_hash' => hash('sha256', 'x'),
                    'captured_at' => now(),
                ],
            ],
            'snapshots.previous_snapshot' => [
                'connector_schema_snapshots',
                ['previous_snapshot_id'],
                'connector_schema_snapshots',
                fn (Workspace $workspace) => self::makeSnapshot($workspace),
                fn (ConnectorSchemaSnapshot $parent) => array_merge(
                    self::snapshotPayloadForAccount($parent->account),
                    ['previous_snapshot_id' => $parent->id]
                ),
            ],
            'snapshot_fields.snapshot' => [
                'connector_schema_snapshot_fields',
                ['snapshot_id'],
                'connector_schema_snapshots',
                fn (Workspace $workspace) => self::makeSnapshot($workspace),
                fn (ConnectorSchemaSnapshot $parent) => [
                    'snapshot_id' => $parent->id,
                    'external_field_key' => 'sku',
                    'normalized_data_type' => 'string',
                    'normalized_payload' => [],
                    'canonical_hash' => hash('sha256', 'sku'),
                ],
            ],
            'diffs.account' => [
                'connector_schema_diffs',
                ['connector_account_id'],
                'connector_accounts',
                fn (Workspace $workspace) => ConnectorAccount::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'connector_definition_id' => ConnectorDefinition::query()->firstOrFail()->id,
                    'name' => 'Diff Account A',
                    'auth_profile' => 'test',
                    'credentials' => ['token' => 'x'],
                ]),
                fn (ConnectorAccount $parent) => self::diffPayloadForAccount($parent),
            ],
            'diffs.from_snapshot' => [
                'connector_schema_diffs',
                ['from_snapshot_id'],
                'connector_schema_snapshots',
                fn (Workspace $workspace) => self::makeSnapshot($workspace),
                fn (ConnectorSchemaSnapshot $parent) => array_merge(
                    self::diffPayloadForAccount($parent->account),
                    ['from_snapshot_id' => $parent->id]
                ),
            ],
            'diffs.to_snapshot' => [
                'connector_schema_diffs',
                ['to_snapshot_id'],
                'connector_schema_snapshots',
                fn (Workspace $workspace) => self::makeSnapshot($workspace),
                fn (ConnectorSchemaSnapshot $parent) => array_merge(
                    self::diffPayloadForAccount($parent->account),
                    ['to_snapshot_id' => $parent->id]
                ),
            ],
            'diff_items.diff' => [
                'connector_schema_diff_items',
                ['connector_schema_diff_id'],
                'connector_schema_diffs',
                fn (Workspace $workspace) => self::makeDiff($workspace),
                fn (ConnectorSchemaDiff $parent) => [
                    'connector_schema_diff_id' => $parent->id,
                    'change_type' => 'added',
                    'external_field_key' => 'sku',
                ],
            ],
            'diff_items.before_field' => [
                'connector_schema_diff_items',
                ['before_snapshot_field_id'],
                'connector_schema_snapshot_fields',
                fn (Workspace $workspace) => self::makeSnapshotField($workspace),
                function (ConnectorSchemaSnapshotField $parent) {
                    $diff = self::makeDiff(Workspace::query()->findOrFail($parent->workspace_id));

                    return [
                        'connector_schema_diff_id' => $diff->id,
                        'before_snapshot_field_id' => $parent->id,
                        'change_type' => 'removed',
                        'external_field_key' => $parent->external_field_key,
                    ];
                },
            ],
            'diff_items.after_field' => [
                'connector_schema_diff_items',
                ['after_snapshot_field_id'],
                'connector_schema_snapshot_fields',
                fn (Workspace $workspace) => self::makeSnapshotField($workspace),
                function (ConnectorSchemaSnapshotField $parent) {
                    $diff = self::makeDiff(Workspace::query()->findOrFail($parent->workspace_id));

                    return [
                        'connector_schema_diff_id' => $diff->id,
                        'after_snapshot_field_id' => $parent->id,
                        'change_type' => 'added',
                        'external_field_key' => $parent->external_field_key,
                    ];
                },
            ],
        ];
    }

    #[Test]
    public function partial_null_update_on_discovery_run_snapshot_pointer_succeeds(): void
    {
        $run = ConnectorDiscoveryRun::factory()->succeeded()->create();
        $snapshot = ConnectorSchemaSnapshot::factory()->create([
            'workspace_id' => $run->workspace_id,
            'connector_account_id' => $run->connector_account_id,
            'connector_schema_source_id' => $run->connector_schema_source_id,
            'discovery_run_id' => $run->id,
        ]);

        $run->update(['snapshot_id' => $snapshot->id]);
        $this->assertSame($snapshot->id, $run->fresh()->snapshot_id);

        DB::table('connector_discovery_runs')
            ->where('id', $run->id)
            ->update(['snapshot_id' => null]);

        $this->assertNull($run->fresh()->snapshot_id);
    }

    #[Test]
    public function workspace_scope_hides_rows_from_other_workspaces_for_all_models(): void
    {
        $workspaceA = Workspace::query()->where('is_default', true)->sole();
        $workspaceB = Workspace::query()->create(['name' => 'Scope B', 'is_default' => false]);

        app(WorkspaceContext::class)->reset();

        $accountB = ConnectorAccount::withoutWorkspaceScope()->create(
            ConnectorAccount::factory()->make(['workspace_id' => $workspaceB->id])->getAttributes()
        );
        $checkB = ConnectorConnectionCheck::withoutWorkspaceScope()->create(
            ConnectorConnectionCheck::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
            ])->getAttributes()
        );
        $runB = ConnectorDiscoveryRun::withoutWorkspaceScope()->create(
            ConnectorDiscoveryRun::factory()->succeeded()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
                'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
            ])->getAttributes()
        );
        $snapshotB = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create(
            ConnectorSchemaSnapshot::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
                'connector_schema_source_id' => $runB->connector_schema_source_id,
                'discovery_run_id' => $runB->id,
            ])->getAttributes()
        );
        $fieldB = ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create(
            ConnectorSchemaSnapshotField::factory()->make([
                'workspace_id' => $workspaceB->id,
                'snapshot_id' => $snapshotB->id,
            ])->getAttributes()
        );
        $diffB = ConnectorSchemaDiff::withoutWorkspaceScope()->create(
            ConnectorSchemaDiff::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
                'connector_schema_source_id' => $snapshotB->connector_schema_source_id,
                'to_snapshot_id' => $snapshotB->id,
            ])->getAttributes()
        );
        $itemB = ConnectorSchemaDiffItem::withoutWorkspaceScope()->create(
            ConnectorSchemaDiffItem::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_schema_diff_id' => $diffB->id,
                'after_snapshot_field_id' => $fieldB->id,
            ])->getAttributes()
        );

        $this->assertTrue($workspaceA->is(app(WorkspaceContext::class)->current()));

        $this->assertNull(ConnectorAccount::query()->find($accountB->id));
        $this->assertNull(ConnectorConnectionCheck::query()->find($checkB->id));
        $this->assertNull(ConnectorDiscoveryRun::query()->find($runB->id));
        $this->assertNull(ConnectorSchemaSnapshot::query()->find($snapshotB->id));
        $this->assertNull(ConnectorSchemaSnapshotField::query()->find($fieldB->id));
        $this->assertNull(ConnectorSchemaDiff::query()->find($diffB->id));
        $this->assertNull(ConnectorSchemaDiffItem::query()->find($itemB->id));
    }

    #[Test]
    public function scoped_relations_do_not_leak_other_workspace_children(): void
    {
        $workspaceB = Workspace::query()->create(['name' => 'Relation B', 'is_default' => false]);
        $accountB = ConnectorAccount::withoutWorkspaceScope()->create(
            ConnectorAccount::factory()->make(['workspace_id' => $workspaceB->id])->getAttributes()
        );
        ConnectorConnectionCheck::withoutWorkspaceScope()->create(
            ConnectorConnectionCheck::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
            ])->getAttributes()
        );

        $accountA = ConnectorAccount::factory()->create();

        $this->assertCount(0, $accountA->connectionChecks()->where('workspace_id', $workspaceB->id)->get());
        $this->assertCount(0, $accountA->discoveryRuns()->where('workspace_id', $workspaceB->id)->get());
        $this->assertCount(0, $accountA->snapshots()->where('workspace_id', $workspaceB->id)->get());
        $this->assertCount(0, $accountA->diffs()->where('workspace_id', $workspaceB->id)->get());

        $snapshot = ConnectorSchemaSnapshot::factory()->create(['connector_account_id' => $accountA->id]);
        $snapshotB = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create(
            ConnectorSchemaSnapshot::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
                'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
                'discovery_run_id' => ConnectorDiscoveryRun::withoutWorkspaceScope()->create(
                    ConnectorDiscoveryRun::factory()->succeeded()->make([
                        'workspace_id' => $workspaceB->id,
                        'connector_account_id' => $accountB->id,
                        'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
                    ])->getAttributes()
                )->id,
            ])->getAttributes()
        );
        $fieldB = ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create(
            ConnectorSchemaSnapshotField::factory()->make([
                'workspace_id' => $workspaceB->id,
                'snapshot_id' => $snapshotB->id,
            ])->getAttributes()
        );

        $this->assertFalse($snapshot->fields()->whereKey($fieldB->id)->exists());

        $diff = ConnectorSchemaDiff::factory()->create([
            'connector_account_id' => $accountA->id,
            'to_snapshot_id' => $snapshot->id,
        ]);
        $diffB = ConnectorSchemaDiff::withoutWorkspaceScope()->create(
            ConnectorSchemaDiff::factory()->make([
                'workspace_id' => $workspaceB->id,
                'connector_account_id' => $accountB->id,
                'connector_schema_source_id' => $snapshotB->connector_schema_source_id,
                'to_snapshot_id' => $snapshotB->id,
            ])->getAttributes()
        );
        $itemB = ConnectorSchemaDiffItem::withoutWorkspaceScope()->create([
            'workspace_id' => $workspaceB->id,
            'connector_schema_diff_id' => $diffB->id,
            'change_type' => 'added',
            'external_field_key' => 'scoped-field',
            'before_snapshot_field_id' => null,
            'after_snapshot_field_id' => null,
            'changed_paths' => null,
        ]);

        $this->assertFalse($diff->items()->whereKey($itemB->id)->exists());
    }

    #[Test]
    public function models_expose_documented_relationships_and_enum_casts(): void
    {
        $account = ConnectorAccount::factory()->create();
        $check = ConnectorConnectionCheck::factory()->create(['connector_account_id' => $account->id]);
        $run = ConnectorDiscoveryRun::factory()->succeeded()->create(['connector_account_id' => $account->id]);
        $snapshot = ConnectorSchemaSnapshot::factory()->create([
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $run->connector_schema_source_id,
            'discovery_run_id' => $run->id,
        ]);
        $field = ConnectorSchemaSnapshotField::factory()->create(['snapshot_id' => $snapshot->id]);
        $diff = ConnectorSchemaDiff::factory()->create([
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $snapshot->connector_schema_source_id,
            'to_snapshot_id' => $snapshot->id,
        ]);
        $item = ConnectorSchemaDiffItem::factory()->create([
            'connector_schema_diff_id' => $diff->id,
            'after_snapshot_field_id' => $field->id,
        ]);

        $this->assertTrue($account->connectorDefinition()->exists());
        $this->assertTrue($account->connectionChecks()->whereKey($check->id)->exists());
        $this->assertTrue($account->discoveryRuns()->whereKey($run->id)->exists());
        $this->assertTrue($account->snapshots()->whereKey($snapshot->id)->exists());
        $this->assertTrue($account->diffs()->whereKey($diff->id)->exists());

        $this->assertTrue($check->account()->is($account));
        $this->assertTrue($run->account()->is($account));
        $this->assertTrue($run->schemaSource()->exists());
        $this->assertTrue($snapshot->discoveryRun()->is($run));
        $this->assertTrue($snapshot->fields()->whereKey($field->id)->exists());
        $this->assertTrue($snapshot->diffAsToSnapshot()->is($diff));
        $this->assertTrue($field->afterDiffItems()->whereKey($item->id)->exists());
        $this->assertTrue($diff->items()->whereKey($item->id)->exists());
        $this->assertTrue($item->diff()->is($diff));

        $definition = $account->connectorDefinition;
        $this->assertTrue($definition->connectorAccounts()->whereKey($account->id)->exists());
        $source = $run->schemaSource;
        $this->assertTrue($source->discoveryRuns()->whereKey($run->id)->exists());
        $this->assertTrue($source->snapshots()->whereKey($snapshot->id)->exists());
        $this->assertTrue($source->diffs()->whereKey($diff->id)->exists());

        $this->assertInstanceOf(ConnectorAccountConnectionStatus::class, $account->connection_status);
        $this->assertInstanceOf(ConnectorConnectionCheckStatus::class, $check->status);
        $this->assertInstanceOf(ConnectorDiscoveryRunStatus::class, $run->status);
        $this->assertInstanceOf(ConnectorSchemaDiffItemChangeType::class, $item->change_type);
    }

    #[Test]
    public function enum_labels_return_translation_keys(): void
    {
        $this->assertSame(
            'connectors.enums.account_connection_status.connected',
            ConnectorAccountConnectionStatus::Connected->label()
        );
        $this->assertSame(
            'connectors.enums.discovery_run_status.queued',
            ConnectorDiscoveryRunStatus::Queued->label()
        );
        $this->assertStringStartsWith('connectors.enums.', ConnectorSchemaDiffItemChangeType::Added->label());
        $this->assertStringStartsWith(
            'connectors.enums.account_connection_status.',
            ConnectorAccountConnectionStatus::options()[ConnectorAccountConnectionStatus::Untested->value]
        );
    }

    #[Test]
    public function credentials_are_encrypted_and_hidden_from_serialization(): void
    {
        $account = ConnectorAccount::factory()->create([
            'credentials' => ['client_secret' => 'super-secret'],
        ]);

        $raw = DB::table('connector_accounts')->where('id', $account->id)->value('credentials');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('super-secret', $raw);

        $array = $account->toArray();
        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertStringNotContainsString('super-secret', $account->toJson());
    }

    #[Test]
    public function active_name_uniqueness_allows_soft_delete_reuse_and_blocks_restore_conflict(): void
    {
        $account = ConnectorAccount::factory()->create(['name' => 'Shared Name']);
        $definitionId = $account->connector_definition_id;
        $workspaceId = $account->workspace_id;

        $this->expectException(QueryException::class);
        ConnectorAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'connector_definition_id' => $definitionId,
            'name' => 'Shared Name',
        ]);
    }

    #[Test]
    public function soft_deleted_account_name_can_be_reused(): void
    {
        $account = ConnectorAccount::factory()->create(['name' => 'Reusable']);
        $account->delete();

        $replacement = ConnectorAccount::factory()->create([
            'workspace_id' => $account->workspace_id,
            'connector_definition_id' => $account->connector_definition_id,
            'name' => 'Reusable',
        ]);

        $this->assertNotSame($account->id, $replacement->id);
    }

    #[Test]
    public function restoring_soft_deleted_account_fails_when_name_is_taken(): void
    {
        $account = ConnectorAccount::factory()->create(['name' => 'Taken']);
        $account->delete();

        ConnectorAccount::factory()->create([
            'workspace_id' => $account->workspace_id,
            'connector_definition_id' => $account->connector_definition_id,
            'name' => 'Taken',
        ]);

        $this->expectException(QueryException::class);
        $account->restore();
    }

    #[Test]
    public function history_models_do_not_write_updated_at(): void
    {
        $check = ConnectorConnectionCheck::factory()->create();
        $run = ConnectorDiscoveryRun::factory()->create();
        $snapshot = ConnectorSchemaSnapshot::factory()->create();
        $field = ConnectorSchemaSnapshotField::factory()->create();
        $diff = ConnectorSchemaDiff::factory()->create();
        $item = ConnectorSchemaDiffItem::factory()->create();

        foreach ([$check, $run, $snapshot, $field, $diff, $item] as $model) {
            $this->assertNull($model->getAttributes()['updated_at'] ?? null);
            $this->assertFalse(Schema::hasColumn($model->getTable(), 'updated_at'));
        }
    }

    #[Test]
    public function diff_enforces_unique_to_snapshot_and_baseline_fixtures(): void
    {
        $baseline = ConnectorSchemaDiff::factory()->create();
        $this->assertTrue($baseline->is_first_snapshot);
        $this->assertNull($baseline->from_snapshot_id);

        $nonBaseline = ConnectorSchemaDiff::factory()->nonBaseline()->create();
        $this->assertFalse($nonBaseline->is_first_snapshot);
        $this->assertNotNull($nonBaseline->from_snapshot_id);

        $this->expectException(QueryException::class);
        ConnectorSchemaDiff::factory()->create(['to_snapshot_id' => $baseline->to_snapshot_id]);
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $result !== [];
    }

    private static function makeDiscoveryRun(Workspace $workspace): ConnectorDiscoveryRun
    {
        $account = ConnectorAccount::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_definition_id' => ConnectorDefinition::query()->firstOrFail()->id,
            'name' => 'Run '.Str::uuid(),
            'auth_profile' => 'test',
            'credentials' => ['token' => 'x'],
        ]);

        return ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
            'trigger' => 'manual',
            'status' => 'queued',
        ]);
    }

    private static function makeSnapshot(Workspace $workspace): ConnectorSchemaSnapshot
    {
        $run = self::makeDiscoveryRun($workspace);

        return ConnectorSchemaSnapshot::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $run->connector_account_id,
            'connector_schema_source_id' => $run->connector_schema_source_id,
            'discovery_run_id' => $run->id,
            'field_count' => 1,
            'canonical_hash' => hash('sha256', 'snapshot'),
            'captured_at' => now(),
        ]);
    }

    private static function makeSnapshotField(Workspace $workspace): ConnectorSchemaSnapshotField
    {
        $snapshot = self::makeSnapshot($workspace);

        return ConnectorSchemaSnapshotField::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'snapshot_id' => $snapshot->id,
            'external_field_key' => 'field_'.Str::uuid(),
            'normalized_data_type' => 'string',
            'normalized_payload' => [],
            'canonical_hash' => hash('sha256', 'field'),
        ]);
    }

    private static function makeDiff(Workspace $workspace): ConnectorSchemaDiff
    {
        $snapshot = self::makeSnapshot($workspace);

        return ConnectorSchemaDiff::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $snapshot->connector_account_id,
            'connector_schema_source_id' => $snapshot->connector_schema_source_id,
            'from_snapshot_id' => null,
            'to_snapshot_id' => $snapshot->id,
            'is_first_snapshot' => true,
            'added_count' => 1,
            'changed_count' => 0,
            'removed_count' => 0,
            'unchanged_count' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotPayloadForAccount(ConnectorAccount $account): array
    {
        $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
            'trigger' => 'manual',
            'status' => 'queued',
        ]);

        return [
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => ConnectorSchemaSource::query()->firstOrFail()->id,
            'discovery_run_id' => $run->id,
            'field_count' => 1,
            'canonical_hash' => hash('sha256', 'payload'),
            'captured_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function diffPayloadForAccount(ConnectorAccount $account): array
    {
        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->create(
            array_merge(
                ['workspace_id' => $account->workspace_id],
                self::snapshotPayloadForAccount($account)
            )
        );

        return [
            'connector_account_id' => $account->id,
            'connector_schema_source_id' => $snapshot->connector_schema_source_id,
            'from_snapshot_id' => null,
            'to_snapshot_id' => $snapshot->id,
            'is_first_snapshot' => true,
            'added_count' => 1,
            'changed_count' => 0,
            'removed_count' => 0,
            'unchanged_count' => 0,
        ];
    }
}
