<?php

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncConfiguration;
use App\Support\Sync\SyncConfigurationRevisionHasher;
use App\Support\Sync\SyncOperationSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->uuid('sync_configuration_id');
            $table->uuid('field_binding_id');
            $table->string('external_field_key');
            $table->timestamps();

            $table->unique(['sync_configuration_id', 'field_binding_id'], 'fm_config_binding_unique');
            $table->unique(['sync_configuration_id', 'external_field_key'], 'fm_config_external_key_unique');
        });

        Schema::table('field_mappings', function (Blueprint $table) {
            $table->foreign(
                ['workspace_id', 'sync_configuration_id'],
                'fm_ws_config_fk',
            )->references(['workspace_id', 'id'])->on('sync_configurations')->cascadeOnDelete();

            $table->foreign('field_binding_id', 'fm_binding_fk')
                ->references('id')
                ->on('field_bindings')
                ->restrictOnDelete();
        });

        $this->rebaselineConfigurationRevisionsToV2();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('field_mappings', function (Blueprint $table) {
                $table->dropForeign('fm_binding_fk');
                $table->dropForeign('fm_ws_config_fk');
            });
        } else {
            Schema::table('field_mappings', function (Blueprint $table) {
                $table->dropForeign(['field_binding_id']);
                $table->dropForeign(['workspace_id', 'sync_configuration_id']);
            });
        }

        Schema::dropIfExists('field_mappings');
    }

    private function rebaselineConfigurationRevisionsToV2(): void
    {
        $hasher = new SyncConfigurationRevisionHasher;

        SyncConfiguration::withoutWorkspaceScope()
            ->orderBy('id')
            ->each(function (SyncConfiguration $configuration) use ($hasher): void {
                $operationValues = $configuration->enabled_operations ?? [];
                $operations = array_map(
                    static fn (string $operation): SyncSemanticOperation => SyncSemanticOperation::from($operation),
                    $operationValues,
                );

                $configuration->configuration_revision = $hasher->hash(
                    SyncOperationSet::fromOperations($operations),
                    $configuration->operational_state ?? SyncConfigurationOperationalState::Enabled,
                    [],
                );
                $configuration->save();
            });
    }
};
