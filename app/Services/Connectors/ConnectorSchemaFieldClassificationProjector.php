<?php

namespace App\Services\Connectors;

use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Support\Connectors\AdobePaaS\AdobeProductAttributeClassifier;
use Illuminate\Support\Str;

final class ConnectorSchemaFieldClassificationProjector
{
    public function __construct(
        private readonly AdobeProductAttributeClassifier $adobeProductAttributeClassifier,
    ) {}

    public function project(
        ConnectorAccount $account,
        ConnectorSchemaSource $source,
        ConnectorSchemaSnapshot $snapshot,
    ): void {
        $connectorCode = $source->connectorDefinition()->value('code');
        if ($connectorCode !== 'adobe_commerce') {
            return;
        }

        $fields = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('external_field_key')
            ->get();

        $existing = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('connector_schema_source_id', $source->id)
            ->pluck('id', 'external_field_key')
            ->all();

        $rows = [];
        $presentKeys = [];
        $now = now();

        foreach ($fields as $field) {
            $decision = $this->adobeProductAttributeClassifier->classify($field);
            $presentKeys[$field->external_field_key] = true;
            $rows[] = [
                'id' => $existing[$field->external_field_key] ?? (string) Str::uuid(),
                'workspace_id' => $account->workspace_id,
                'connector_account_id' => $account->id,
                'connector_schema_source_id' => $source->id,
                'external_field_key' => $field->external_field_key,
                'latest_snapshot_field_id' => $field->id,
                'disposition' => $decision->disposition->value,
                'behavior_class' => $decision->behaviorClass,
                'behavior_signature' => $decision->behaviorSignature === null
                    ? null
                    : json_encode($decision->behaviorSignature, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'runtime_owner_hint' => $decision->runtimeOwnerHint,
                'canonical_code' => $decision->canonicalCode,
                'mapping_strategy' => $decision->mappingStrategy,
                'classifier_version' => $decision->classifierVersion,
                'reason_code' => $decision->reasonCode,
                'classified_canonical_hash' => $field->canonical_hash,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            ConnectorSchemaFieldClassification::withoutWorkspaceScope()->upsert(
                $chunk,
                ['workspace_id', 'connector_account_id', 'connector_schema_source_id', 'external_field_key'],
                [
                    'latest_snapshot_field_id', 'disposition',
                    'behavior_class', 'behavior_signature', 'runtime_owner_hint', 'canonical_code',
                    'mapping_strategy', 'classifier_version', 'reason_code', 'classified_canonical_hash',
                    'computed_at', 'updated_at',
                ],
            );
        }

        $removedIds = [];
        foreach ($existing as $externalFieldKey => $id) {
            if (! isset($presentKeys[$externalFieldKey])) {
                $removedIds[] = $id;
            }
        }
        foreach (array_chunk($removedIds, 250) as $chunk) {
            ConnectorSchemaFieldClassification::withoutWorkspaceScope()->whereIn('id', $chunk)->delete();
        }
    }
}
