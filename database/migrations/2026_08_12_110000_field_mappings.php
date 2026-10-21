<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REVISION_V1_PREFIX = 'babypark.sync-configuration-revision.v1';

    private const REVISION_V2_PREFIX = 'babypark.sync-configuration-revision.v2';

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

        $this->rebaselineConfigurationRevisionsToV1();
    }

    private function rebaselineConfigurationRevisionsToV1(): void
    {
        $rows = DB::table('sync_configurations')
            ->orderBy('id')
            ->get(['id', 'enabled_operations', 'operational_state']);

        foreach ($rows as $row) {
            /** @var list<string>|null $operationValues */
            $operationValues = json_decode((string) $row->enabled_operations, true);

            if (! is_array($operationValues)) {
                $operationValues = [];
            }

            $revision = $this->hashRevisionV1(
                $this->canonicalizePersistedOperations($operationValues),
                (string) ($row->operational_state ?? 'enabled'),
            );

            DB::table('sync_configurations')
                ->where('id', $row->id)
                ->update(['configuration_revision' => $revision]);
        }
    }

    private function rebaselineConfigurationRevisionsToV2(): void
    {
        $rows = DB::table('sync_configurations')
            ->orderBy('id')
            ->get(['id', 'enabled_operations', 'operational_state']);

        foreach ($rows as $row) {
            /** @var list<string>|null $operationValues */
            $operationValues = json_decode((string) $row->enabled_operations, true);

            if (! is_array($operationValues)) {
                $operationValues = [];
            }

            $revision = $this->hashRevisionV2EmptyMappings(
                $this->canonicalizePersistedOperations($operationValues),
                (string) ($row->operational_state ?? 'enabled'),
            );

            DB::table('sync_configurations')
                ->where('id', $row->id)
                ->update(['configuration_revision' => $revision]);
        }
    }

    /**
     * @param  list<string>  $operations
     * @return list<string>
     */
    private function canonicalizePersistedOperations(array $operations): array
    {
        if (! array_is_list($operations)) {
            return [];
        }

        $unique = [];

        foreach ($operations as $operation) {
            if (! is_string($operation)) {
                continue;
            }

            $unique[$operation] = true;
        }

        $canonical = array_keys($unique);
        sort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  list<string>  $enabledOperations
     */
    private function hashRevisionV1(array $enabledOperations, string $operationalState): string
    {
        $payload = new stdClass;
        $payload->enabled_operations = $enabledOperations;
        $payload->operational_state = $operationalState;

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::REVISION_V1_PREFIX."\n".$json);
    }

    /**
     * @param  list<string>  $enabledOperations
     */
    private function hashRevisionV2EmptyMappings(array $enabledOperations, string $operationalState): string
    {
        $payload = new stdClass;
        $payload->enabled_operations = $enabledOperations;
        $payload->operational_state = $operationalState;
        $payload->field_mappings = [];

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::REVISION_V2_PREFIX."\n".$json);
    }

    private function encodeCanonicalJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function sortObjectKeysRecursively(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $array = (array) $value;
            ksort($array, SORT_STRING);

            $result = new stdClass;

            foreach ($array as $key => $nested) {
                $result->{$key} = $this->sortObjectKeysRecursively($nested);
            }

            return $result;
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->sortObjectKeysRecursively($item),
                $value,
            );
        }

        return $value;
    }
};
