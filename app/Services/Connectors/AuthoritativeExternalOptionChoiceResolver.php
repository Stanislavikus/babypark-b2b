<?php

namespace App\Services\Connectors;

use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshotField;
use App\Support\Sync\FieldOptionMappingReadModel\ExternalOptionChoice;

final class AuthoritativeExternalOptionChoiceResolver
{
    public function __construct(
        private readonly AuthoritativeConnectorSchemaSnapshotResolver $snapshotResolver,
    ) {}

    /**
     * @return list<ExternalOptionChoice>
     */
    public function resolveChoices(ConnectorAccount $account, string $externalFieldKey): array
    {
        $snapshot = $this->snapshotResolver->resolveSnapshot($account);

        if ($snapshot === null) {
            return [];
        }

        $field = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', $externalFieldKey)
            ->first();

        if ($field === null) {
            return [];
        }

        return $this->extractOptions($field);
    }

    public function externalFieldPresent(ConnectorAccount $account, string $externalFieldKey): bool
    {
        $snapshot = $this->snapshotResolver->resolveSnapshot($account);

        if ($snapshot === null) {
            return false;
        }

        return ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('snapshot_id', $snapshot->id)
            ->where('external_field_key', $externalFieldKey)
            ->exists();
    }

    public function labelForValue(ConnectorAccount $account, string $externalFieldKey, string $value): ?string
    {
        foreach ($this->resolveChoices($account, $externalFieldKey) as $choice) {
            if ($choice->value === $value) {
                return $choice->presentationLabel();
            }
        }

        return null;
    }

    /**
     * @return list<ExternalOptionChoice>
     */
    private function extractOptions(ConnectorSchemaSnapshotField $field): array
    {
        $payload = $field->normalized_payload;

        if (! is_object($payload) || ! property_exists($payload, 'options')) {
            return [];
        }

        $options = $payload->options;

        if (! is_array($options)) {
            return [];
        }

        $choices = [];

        foreach ($options as $option) {
            if (! is_object($option)) {
                continue;
            }

            $value = $option->value ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $label = $option->label ?? null;

            $choices[] = new ExternalOptionChoice(
                value: $value,
                label: is_string($label) ? $label : '',
            );
        }

        usort(
            $choices,
            static fn (ExternalOptionChoice $left, ExternalOptionChoice $right): int => strcmp(
                $left->presentationLabel(),
                $right->presentationLabel(),
            ),
        );

        return $choices;
    }
}
