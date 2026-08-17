<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REVISION_V3_PREFIX = 'babypark.sync-configuration-revision.v3';

    private const REVISION_V4_PREFIX = 'babypark.sync-configuration-revision.v4';

    public function up(): void
    {
        $this->rebaselineConfigurationRevisionsToV4();
    }

    public function down(): void
    {
        $this->rebaselineConfigurationRevisionsToV3();
    }

    private function rebaselineConfigurationRevisionsToV4(): void
    {
        $rows = DB::table('sync_configurations')
            ->orderBy('id')
            ->get(['id', 'enabled_operations', 'operational_state', 'connector_execution_configuration']);

        foreach ($rows as $row) {
            $revision = $this->hashRevisionV4(
                $this->canonicalizePersistedOperations($this->decodeOperations($row->enabled_operations)),
                (string) ($row->operational_state ?? 'enabled'),
                $this->canonicalFieldMappingsForConfiguration((string) $row->id),
                $this->decodeConnectorExecutionConfiguration($row->connector_execution_configuration),
            );

            DB::table('sync_configurations')
                ->where('id', $row->id)
                ->update(['configuration_revision' => $revision]);
        }
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
                $this->canonicalFieldMappingsForConfigurationV3((string) $row->id),
            );

            DB::table('sync_configurations')
                ->where('id', $row->id)
                ->update(['configuration_revision' => $revision]);
        }
    }

    /**
     * @return list<array{
     *     field_binding_id: string,
     *     external_field_key: string,
     *     option_mappings: list<array{internal_option_key: string, external_option_value: string}>
     * }>
     */
    private function canonicalFieldMappingsForConfiguration(string $configurationId): array
    {
        return DB::table('field_mappings')
            ->where('sync_configuration_id', $configurationId)
            ->orderBy('field_binding_id')
            ->orderBy('external_field_key')
            ->get(['id', 'field_binding_id', 'external_field_key'])
            ->map(function (object $mapping): array {
                $optionMappings = DB::table('field_option_mappings')
                    ->where('field_mapping_id', (string) $mapping->id)
                    ->orderBy('internal_option_key')
                    ->get(['internal_option_key', 'external_option_value'])
                    ->map(static fn (object $option): array => [
                        'internal_option_key' => (string) $option->internal_option_key,
                        'external_option_value' => (string) $option->external_option_value,
                    ])
                    ->all();

                return [
                    'field_binding_id' => (string) $mapping->field_binding_id,
                    'external_field_key' => (string) $mapping->external_field_key,
                    'option_mappings' => $optionMappings,
                ];
            })
            ->all();
    }

    /**
     * @return list<array{field_binding_id: string, external_field_key: string}>
     */
    private function canonicalFieldMappingsForConfigurationV3(string $configurationId): array
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
     * @return array<string, mixed>
     */
    private function decodeConnectorExecutionConfiguration(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        ksort($decoded, SORT_STRING);

        return $decoded;
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
     * @param  list<array{
     *     field_binding_id: string,
     *     external_field_key: string,
     *     option_mappings: list<array{internal_option_key: string, external_option_value: string}>
     * }>  $fieldMappings
     * @param  array<string, mixed>  $connectorExecutionConfiguration
     */
    private function hashRevisionV4(
        array $enabledOperations,
        string $operationalState,
        array $fieldMappings,
        array $connectorExecutionConfiguration,
    ): string {
        $payload = new stdClass;
        $payload->enabled_operations = $enabledOperations;
        $payload->operational_state = $operationalState;
        $selection = new stdClass;
        $selection->mode = 'all_products';
        $payload->selection = $selection;
        $payload->connector_execution_configuration = $connectorExecutionConfiguration;
        $payload->field_mappings = $fieldMappings;

        $json = $this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload));

        return hash('sha256', self::REVISION_V4_PREFIX."\n".$json);
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
