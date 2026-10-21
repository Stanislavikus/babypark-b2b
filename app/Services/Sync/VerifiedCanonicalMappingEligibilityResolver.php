<?php

namespace App\Services\Sync;

use App\Enums\ConnectorSchemaFieldDisposition;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaFieldClassification;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;

final class VerifiedCanonicalMappingEligibilityResolver
{
    /**
     * @param  array<string, ConnectorSchemaSnapshotField>  $snapshotFieldsByKey
     * @return array<string, true>
     */
    public function eligibleExternalKeys(
        ConnectorAccount $account,
        ConnectorSchemaSnapshot $snapshot,
        array $snapshotFieldsByKey,
    ): array {
        $account->loadMissing('connectorDefinition');

        if ($account->connectorDefinition?->code !== 'adobe_commerce') {
            return array_fill_keys(array_keys($snapshotFieldsByKey), true);
        }

        if (($snapshot->canonical_hash_version ?? 'v1') !== 'v2') {
            return array_fill_keys(array_keys($snapshotFieldsByKey), true);
        }

        $fieldIdsByKey = [];
        foreach ($snapshotFieldsByKey as $key => $field) {
            $fieldIdsByKey[$key] = $field->id;
        }

        if ($fieldIdsByKey === []) {
            return [];
        }

        $eligibleIds = ConnectorSchemaFieldClassification::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('connector_account_id', $account->id)
            ->where('connector_schema_source_id', $snapshot->connector_schema_source_id)
            ->where('disposition', ConnectorSchemaFieldDisposition::CanonicalPlatform->value)
            ->where('mapping_strategy', 'verified_channel_mapping_rule')
            ->whereIn('latest_snapshot_field_id', array_values($fieldIdsByKey))
            ->pluck('latest_snapshot_field_id')
            ->all();

        $eligibleIdSet = array_fill_keys($eligibleIds, true);
        $keys = [];
        foreach ($fieldIdsByKey as $key => $fieldId) {
            if (isset($eligibleIdSet[$fieldId])) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }
}
