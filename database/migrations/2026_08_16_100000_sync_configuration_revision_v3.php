<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REVISION_V2_PREFIX = 'babypark.sync-configuration-revision.v2';

    private const REVISION_V3_PREFIX = 'babypark.sync-configuration-revision.v3';

    public function up(): void
    {
        $this->rebaselineConfigurationRevisionsToV3();
    }

    public function down(): void
    {
        $this->rebaselineConfigurationRevisionsToV2();
    }

    private function rebaselineConfigurationRevisionsToV3(): void
    {
        $rows = DB::table('sync_configurations')
            ->orderBy('id')
            ->get(['id', 'enabled_operations', 'operational_state']);

        foreach ($rows as $row) {
            $revision = $this->hashRevisionV3(
                $this->canonicalizePersistedOperations($this->decodeOperations($row->enabled_operations)),
                (string) ($row->operational_state ?? 'enabled'),
                $this->canonicalFieldMappingsForConfiguration((string) $row->id),
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
            $revision = $this->hashRevisionV2(
                $this->canonicalizePersistedOperations($this->decodeOperations($row->enabled_operations)),
                (string) ($row->operational_state ?? 'enabled'),
                $this->canonicalFieldMappingsForConfiguration((string) $row->id),
            );

            DB::table('sync_configurations')
                ->where('id', $row->id)
                ->update(['configuration_revision' => $revision]);
        }
    }

    /**
     * @return list<array{field_binding_id: string, external_field_key: string}>
     */
    private function canonicalFieldMappingsForConfiguration(string $configurationId): array
    {
        return DB::table('field_mappings')
            ->where('sync_configuration_id', $configurationId)
            ->orderBy('field_binding_id')
            ->orderBy('external_field_key')
            ->get(['field_binding_id', 'external_field_key'])
            ->map(static fn (object $mapping): array => [
                'field_binding_id' => (string) $mapping->field_binding_id,
                'external_field_key' => (string) $mapping->external_field_key,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function decodeOperations(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        /** @var list<string>|null $operationValues */
        $operationValues = json_decode($raw, true);

        if (! is_array($operationValues)) {
            return [];
        }

        return $operationValues;
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
     * @param  list<array{field_binding_id: string, external_field_key: string}>  $fieldMappings
     */
    private function hashRevisionV2(array $enabledOperations, string $operationalState, array $fieldMappings): string
    {
        $payload = new stdClass;
        $payload->enabled_operations = $enabledOperations;
        $payload->operational_state = $operationalState;
        $payload->field_mappings = $fieldMappings;

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::REVISION_V2_PREFIX."\n".$json);
    }

    /**
     * @param  list<string>  $enabledOperations
     * @param  list<array{field_binding_id: string, external_field_key: string}>  $fieldMappings
     */
    private function hashRevisionV3(array $enabledOperations, string $operationalState, array $fieldMappings): string
    {
        $payload = new stdClass;
        $payload->enabled_operations = $enabledOperations;
        $payload->operational_state = $operationalState;
        $selection = new stdClass;
        $selection->mode = 'all_products';
        $payload->selection = $selection;
        $payload->field_mappings = $fieldMappings;

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::REVISION_V3_PREFIX."\n".$json);
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
