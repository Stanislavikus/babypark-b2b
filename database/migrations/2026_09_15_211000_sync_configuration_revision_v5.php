<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REVISION_V4_PREFIX = 'platform.sync-configuration-revision.v4';

    private const REVISION_V5_PREFIX = 'platform.sync-configuration-revision.v5';

    public function up(): void
    {
        $this->rebaselineConfigurationRevisionsToV5();
    }

    public function down(): void
    {
        $this->rebaselineConfigurationRevisionsToV4();
    }

    private function rebaselineConfigurationRevisionsToV5(): void
    {
        $rows = DB::table('sync_configurations')
            ->orderBy('id')
            ->get(['id', 'enabled_operations', 'operational_state', 'connector_execution_configuration']);

        foreach ($rows as $row) {
            $revision = $this->hashRevisionV5(
                $this->canonicalizePersistedOperations($this->decodeOperations($row->enabled_operations)),
                (string) ($row->operational_state ?? 'enabled'),
                $this->canonicalFieldMappingsForConfiguration((string) $row->id),
                $this->decodeConnectorExecutionConfiguration($row->connector_execution_configuration),
                $this->selectedProductIdsForConfiguration((string) $row->id),
            );

            DB::table('sync_configurations')->where('id', $row->id)->update([
                'configuration_revision' => $revision,
            ]);
        }
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

            DB::table('sync_configurations')->where('id', $row->id)->update([
                'configuration_revision' => $revision,
            ]);
        }
    }

    /** @return list<int> */
    private function selectedProductIdsForConfiguration(string $configurationId): array
    {
        return DB::table('sync_configuration_product_selections')
            ->where('sync_configuration_id', $configurationId)
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<array{field_binding_id: string, external_field_key: string, option_mappings: list<array{internal_option_key: string, external_option_value: string}>}>
     */
    private function canonicalFieldMappingsForConfiguration(string $configurationId): array
    {
        return DB::table('field_mappings')
            ->where('sync_configuration_id', $configurationId)
            ->orderBy('field_binding_id')
            ->orderBy('external_field_key')
            ->get(['id', 'field_binding_id', 'external_field_key'])
            ->map(function (object $mapping): array {
                $options = DB::table('field_option_mappings')
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
                    'option_mappings' => $options,
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function decodeConnectorExecutionConfiguration(mixed $raw): array
    {
        if (is_array($raw)) {
            return $this->canonicalizeConnectorExecutionConfiguration($raw);
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? $this->canonicalizeConnectorExecutionConfiguration($decoded)
            : [];
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function canonicalizeConnectorExecutionConfiguration(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->canonicalizeConnectorExecutionConfiguration($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        $canonical = [];

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $canonical[$key] = is_array($nested)
                    ? $this->canonicalizeConnectorExecutionConfiguration($nested)
                    : $nested;
            }
        }

        return $canonical;
    }

    /** @return list<string> */
    private function decodeOperations(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<string> $operations @return list<string> */
    private function canonicalizePersistedOperations(array $operations): array
    {
        $unique = [];
        foreach ($operations as $operation) {
            if (is_string($operation)) {
                $unique[$operation] = true;
            }
        }

        $canonical = array_keys($unique);
        sort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  list<string>  $enabledOperations
     * @param  list<array{field_binding_id: string, external_field_key: string, option_mappings: list<array{internal_option_key: string, external_option_value: string}>}>  $fieldMappings
     * @param  array<string, mixed>  $connectorExecutionConfiguration
     * @param  list<int>  $selectedProductIds
     */
    private function hashRevisionV5(
        array $enabledOperations,
        string $operationalState,
        array $fieldMappings,
        array $connectorExecutionConfiguration,
        array $selectedProductIds,
    ): string {
        sort($selectedProductIds, SORT_NUMERIC);

        $payload = new stdClass;
        $payload->enabled_operations = $enabledOperations;
        $payload->operational_state = $operationalState;
        $selection = new stdClass;
        $selection->mode = 'explicit_products';
        $selection->product_count = count($selectedProductIds);
        $selection->product_ids_hash = hash('sha256', implode("\n", $selectedProductIds));
        $payload->selection = $selection;
        $payload->connector_execution_configuration = $this->canonicalizeConnectorExecutionConfiguration($connectorExecutionConfiguration);
        $payload->field_mappings = $fieldMappings;

        return hash('sha256', self::REVISION_V5_PREFIX."\n".$this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload)));
    }

    /**
     * @param  list<string>  $enabledOperations
     * @param  list<array{field_binding_id: string, external_field_key: string, option_mappings: list<array{internal_option_key: string, external_option_value: string}>}>  $fieldMappings
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
        $payload->connector_execution_configuration = $this->canonicalizeConnectorExecutionConfiguration($connectorExecutionConfiguration);
        $payload->field_mappings = $fieldMappings;

        return hash('sha256', self::REVISION_V4_PREFIX."\n".$this->encodeCanonicalJson($this->sortObjectKeysRecursively($payload)));
    }

    private function encodeCanonicalJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
            return array_map(fn (mixed $item): mixed => $this->sortObjectKeysRecursively($item), $value);
        }

        return $value;
    }
};
