<?php

namespace App\Support\CanonicalRegistry;

class FieldMatrixPresenter
{
    public function __construct(
        private readonly CanonicalRegistryReader $reader = new CanonicalRegistryReader,
    ) {}

    /**
     * @param  list<array{channel: string, channel_schema_version: string}>  $columns
     * @return list<array{
     *   internal_code: string,
     *   uk_label: string,
     *   cells: array<string, array{
     *     label: string,
     *     integrity_alarm: bool,
     *     contexts: list<array<string, mixed>>
     *   }>
     * }>
     */
    public function buildMatrix(array $columns): array
    {
        $matrix = [];

        foreach ($this->reader->fields() as $field) {
            $cells = [];
            foreach ($columns as $column) {
                $key = $column['channel'].'|'.$column['channel_schema_version'];
                $cells[$key] = $this->buildCell(
                    $field['internal_code'],
                    $column['channel'],
                    $column['channel_schema_version'],
                );
            }

            $matrix[] = [
                'internal_code' => $field['internal_code'],
                'uk_label' => $field['uk_label'],
                'cells' => $cells,
            ];
        }

        return $matrix;
    }

    /**
     * @return array{label: string, integrity_alarm: bool, contexts: list<array<string, mixed>>}
     */
    private function buildCell(string $internalCode, string $channel, string $schemaVersion): array
    {
        $mappings = collect($this->reader->mappings())
            ->filter(fn (array $row): bool => $row['internal_code'] === $internalCode
                && $row['channel'] === $channel
                && $row['channel_schema_version'] === $schemaVersion)
            ->values()
            ->all();

        $decisions = collect($this->reader->channelDecisions())
            ->filter(fn (array $row): bool => $row['internal_code'] === $internalCode
                && $row['channel'] === $channel
                && $row['channel_schema_version'] === $schemaVersion)
            ->values()
            ->all();

        if ($mappings === [] && $decisions === []) {
            return [
                'label' => 'Not assessed',
                'integrity_alarm' => false,
                'contexts' => [],
            ];
        }

        $contexts = [];
        $integrityAlarm = false;

        foreach ($mappings as $mapping) {
            $applicabilityId = $mapping['applicability_id'];
            $contexts[$applicabilityId]['mapping'] = $mapping;
        }

        foreach ($decisions as $decision) {
            $applicabilityId = $decision['applicability_id_or_state'];
            if (isset($contexts[$applicabilityId]['mapping'])) {
                $integrityAlarm = true;
            }
            $contexts[$applicabilityId]['decision'] = $decision;
        }

        if (count($contexts) === 1) {
            $context = reset($contexts);
            if (isset($context['mapping'], $context['decision'])) {
                return [
                    'label' => 'DATA INTEGRITY ALARM',
                    'integrity_alarm' => true,
                    'contexts' => $this->formatContexts($contexts),
                ];
            }

            if (isset($context['mapping'])) {
                return [
                    'label' => $context['mapping']['mapping_type'],
                    'integrity_alarm' => false,
                    'contexts' => $this->formatContexts($contexts),
                ];
            }

            return [
                'label' => $context['decision']['decision_state'],
                'integrity_alarm' => false,
                'contexts' => $this->formatContexts($contexts),
            ];
        }

        $hasMappingOnly = collect($contexts)->every(fn (array $ctx): bool => isset($ctx['mapping']) && ! isset($ctx['decision']));
        $hasDecisionOnly = collect($contexts)->every(fn (array $ctx): bool => isset($ctx['decision']) && ! isset($ctx['mapping']));

        if ($hasMappingOnly) {
            $labels = collect($contexts)
                ->map(fn (array $ctx): string => $ctx['mapping']['mapping_type'])
                ->unique()
                ->values();

            return [
                'label' => $labels->count() === 1 ? $labels->first() : 'Mixed',
                'integrity_alarm' => $integrityAlarm,
                'contexts' => $this->formatContexts($contexts),
            ];
        }

        if ($hasDecisionOnly) {
            $labels = collect($contexts)
                ->map(fn (array $ctx): string => $ctx['decision']['decision_state'])
                ->unique()
                ->values();

            return [
                'label' => $labels->count() === 1 ? $labels->first() : 'Mixed',
                'integrity_alarm' => $integrityAlarm,
                'contexts' => $this->formatContexts($contexts),
            ];
        }

        return [
            'label' => 'Mixed',
            'integrity_alarm' => $integrityAlarm,
            'contexts' => $this->formatContexts($contexts),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $contexts
     * @return list<array<string, mixed>>
     */
    private function formatContexts(array $contexts): array
    {
        $formatted = [];
        foreach ($contexts as $applicabilityId => $context) {
            $formatted[] = [
                'applicability_id' => $applicabilityId,
                'mapping' => $context['mapping'] ?? null,
                'decision' => $context['decision'] ?? null,
            ];
        }

        return $formatted;
    }
}
