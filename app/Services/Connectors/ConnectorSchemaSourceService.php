<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectorSchemaSourceService
{
    public function setPrimary(ConnectorSchemaSource $source, bool $isPrimary): ConnectorSchemaSource
    {
        return DB::transaction(function () use ($source, $isPrimary): ConnectorSchemaSource {
            ConnectorDefinition::query()
                ->whereKey($source->connector_definition_id)
                ->lockForUpdate()
                ->first();

            if ($isPrimary) {
                ConnectorSchemaSource::query()
                    ->where('connector_definition_id', $source->connector_definition_id)
                    ->where('schema_scope', $source->schema_scope)
                    ->where('id', '!=', $source->id)
                    ->update(['is_primary' => false]);
            }

            $source->is_primary = $isPrimary;
            $source->save();

            return $source->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateInvariants(array $data, ?ConnectorSchemaSource $existing = null): void
    {
        $sourceKind = $data['source_kind'] ?? $existing?->source_kind?->value;
        $schemaScope = $data['schema_scope'] ?? $existing?->schema_scope?->value;
        $acquisitionMode = $data['acquisition_mode'] ?? $existing?->acquisition_mode?->value;
        $endpointPath = $data['endpoint_path'] ?? $existing?->endpoint_path;
        $referenceUrl = $data['reference_url'] ?? $existing?->reference_url;
        $verificationStatus = $data['verification_status'] ?? $existing?->verification_status?->value;
        $lastVerifiedAt = $data['last_verified_at'] ?? $existing?->last_verified_at;

        if ($sourceKind === ConnectorSchemaSourceKind::AccountApi->value) {
            if ($schemaScope !== ConnectorSchemaScope::Account->value) {
                throw ValidationException::withMessages([
                    'schema_scope' => 'account_api джерела повинні мати schema_scope=account.',
                ]);
            }

            if ($acquisitionMode !== 'live_fetch') {
                throw ValidationException::withMessages([
                    'acquisition_mode' => 'account_api джерела повинні мати acquisition_mode=live_fetch.',
                ]);
            }

            if ($endpointPath === null || $endpointPath === '') {
                throw ValidationException::withMessages([
                    'endpoint_path' => 'account_api джерела повинні мати endpoint_path.',
                ]);
            }
        }

        if ($schemaScope === ConnectorSchemaScope::Global->value && $endpointPath !== null && $endpointPath !== '') {
            throw ValidationException::withMessages([
                'endpoint_path' => 'Глобальні джерела не можуть мати endpoint_path.',
            ]);
        }

        if ($verificationStatus === ConnectorSchemaVerificationStatus::Verified->value && empty($lastVerifiedAt)) {
            throw ValidationException::withMessages([
                'last_verified_at' => 'verified вимагає last_verified_at.',
            ]);
        }

        if ($referenceUrl !== null && $referenceUrl !== '' && ! filter_var($referenceUrl, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'reference_url' => 'reference_url повинен бути дійсним абсолютним URL.',
            ]);
        }
    }
}
