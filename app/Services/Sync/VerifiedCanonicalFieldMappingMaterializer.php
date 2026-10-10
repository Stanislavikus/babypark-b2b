<?php

namespace App\Services\Sync;

use App\Enums\SyncDataDomain;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\FieldMapping;
use App\Models\SyncConfiguration;
use App\Services\Connectors\AuthoritativeConnectorSchemaSnapshotResolver;
use App\Support\Sync\Exceptions\FieldMappingConflictException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;

final class VerifiedCanonicalFieldMappingMaterializer
{
    public function __construct(
        private readonly AuthoritativeConnectorSchemaSnapshotResolver $snapshotResolver,
        private readonly VerifiedCanonicalMappingEligibilityResolver $eligibilityResolver,
        private readonly CanonicalFieldMappingSuggestionProvider $suggestionProvider,
        private readonly FieldMappingMutationService $mutationService,
    ) {}

    /** @return array{configurations: int, candidates: int, materialized: int, skipped: int} */
    public function materialize(string $workspaceId, string $connectorAccountId): array
    {
        $account = ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereKey($connectorAccountId)
            ->with('connectorDefinition')
            ->firstOrFail();

        $snapshot = $this->snapshotResolver->resolveSnapshot($account);
        if ($snapshot === null) {
            return ['configurations' => 0, 'candidates' => 0, 'materialized' => 0, 'skipped' => 0];
        }

        /** @var array<string, ConnectorSchemaSnapshotField> $fieldsByKey */
        $fieldsByKey = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('snapshot_id', $snapshot->id)
            ->get()
            ->keyBy('external_field_key')
            ->all();

        $eligibleExternalKeys = $this->eligibilityResolver->eligibleExternalKeys($account, $snapshot, $fieldsByKey);
        if ($eligibleExternalKeys === []) {
            return ['configurations' => 0, 'candidates' => 0, 'materialized' => 0, 'skipped' => 0];
        }

        $configurations = SyncConfiguration::withoutWorkspaceScope()->where('workspace_id', $workspaceId)
            ->where('connector_account_id', $account->id)
            ->where('data_domain', SyncDataDomain::Products->value)
            ->get();

        $candidateCount = 0;
        $materialized = 0;
        $skipped = 0;

        foreach ($configurations as $configuration) {
            $existingMappings = FieldMapping::withoutWorkspaceScope()
                ->where('sync_configuration_id', $configuration->id)
                ->get();

            $suggestions = $this->suggestionProvider->suggest(
                $workspaceId,
                $account->connectorDefinition->code,
                $eligibleExternalKeys,
                array_fill_keys($existingMappings->pluck('field_binding_id')->all(), true),
                array_fill_keys($existingMappings->pluck('external_field_key')->all(), true),
            );

            $candidateCount += count($suggestions);

            foreach ($suggestions as $fieldBindingId => $externalFieldKey) {
                try {
                    $this->mutationService->confirm(
                        $account,
                        $configuration->id,
                        $fieldBindingId,
                        $externalFieldKey,
                    );
                    $materialized++;
                } catch (FieldMappingConflictException|FieldMappingValidationException $exception) {
                    report($exception);
                    $skipped++;
                }
            }
        }

        return [
            'configurations' => $configurations->count(),
            'candidates' => $candidateCount,
            'materialized' => $materialized,
            'skipped' => $skipped,
        ];
    }
}
