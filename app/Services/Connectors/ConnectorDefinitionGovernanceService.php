<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Enums\ConnectorSchemaVerificationStatus;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorSchemaSource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectorDefinitionGovernanceService
{
    public function createDefinition(array $data): ConnectorDefinition
    {
        return DB::transaction(function () use ($data): ConnectorDefinition {
            $normalize = $this->normalizeScalar(...);

            if (array_key_exists('status', $data)) {
                $status = $normalize($data['status']);

                if ($status !== ConnectorDefinitionStatus::Draft->value) {
                    throw ValidationException::withMessages([
                        'status' => 'Нову платформу можна створити лише в статусі draft.',
                    ]);
                }
            }

            if (! array_key_exists('code', $data) || $data['code'] === '') {
                throw ValidationException::withMessages([
                    'code' => 'Код обов\'язковий.',
                ]);
            }

            $payload = Arr::only($data, ['code', 'name', 'direction', 'notes']);
            $payload['status'] = ConnectorDefinitionStatus::Draft->value;

            return ConnectorDefinition::query()->create($payload);
        });
    }

    public function updateDefinition(ConnectorDefinition $definition, array $data): ConnectorDefinition
    {
        return DB::transaction(function () use ($definition, $data): ConnectorDefinition {
            $lockedDefinition = $this->lockDefinition($definition);
            $normalize = $this->normalizeScalar(...);

            if (array_key_exists('code', $data) && $data['code'] !== $lockedDefinition->code) {
                throw ValidationException::withMessages([
                    'code' => 'Код платформи не можна змінити.',
                ]);
            }

            $allowed = Arr::only($data, ['name', 'direction', 'status', 'notes']);

            foreach (['name', 'direction', 'notes'] as $field) {
                if (array_key_exists($field, $allowed)) {
                    $lockedDefinition->{$field} = $allowed[$field];
                }
            }

            if (array_key_exists('status', $allowed)) {
                $lockedDefinition->status = $allowed['status'];
            }

            $finalStatus = $normalize($allowed['status'] ?? $lockedDefinition->status);

            if ($finalStatus === ConnectorDefinitionStatus::Active->value
                && ! $lockedDefinition->hasVerifiedGlobalPrimarySource()) {
                throw ValidationException::withMessages([
                    'status' => 'Активна платформа потребує перевірене первинне глобальне джерело схеми.',
                ]);
            }

            $lockedDefinition->save();

            return $lockedDefinition->refresh();
        });
    }

    public function createSource(ConnectorDefinition $definition, array $data): ConnectorSchemaSource
    {
        return DB::transaction(function () use ($definition, $data): ConnectorSchemaSource {
            $lockedDefinition = $this->lockDefinition($definition);

            if (
                array_key_exists('connector_definition_id', $data)
                && $data['connector_definition_id'] !== $lockedDefinition->getKey()
            ) {
                throw ValidationException::withMessages([
                    'connector_definition_id' => 'Джерело не можна перенести до іншої платформи.',
                ]);
            }

            $payload = Arr::only($data, [
                'code', 'label', 'source_kind', 'acquisition_mode', 'schema_scope',
                'reference_url', 'endpoint_path', 'schema_version', 'is_primary',
                'verification_status', 'last_verified_at', 'notes', 'sort_order',
            ]);

            $payload = array_replace([
                'is_primary' => false,
                'sort_order' => 0,
                'reference_url' => null,
                'endpoint_path' => null,
                'schema_version' => null,
                'last_verified_at' => null,
                'notes' => null,
            ], $payload);

            $normalize = $this->normalizeScalar(...);
            $payload = array_map($normalize, $payload);
            $payload['is_primary'] = $this->normalizeBoolean($payload['is_primary'], 'is_primary');

            $this->validateInvariants($payload);

            if ($payload['is_primary'] === true) {
                $this->unsetOtherPrimarySourcesWithinLockedTransaction(
                    $lockedDefinition,
                    $payload['schema_scope'],
                );
            }

            $source = $lockedDefinition->schemaSources()->create($payload);

            $this->ensureActiveDefinitionHasQualifyingSourceOrDraft($lockedDefinition);

            return $source->refresh();
        });
    }

    public function updateSource(ConnectorSchemaSource $source, array $data): ConnectorSchemaSource
    {
        return DB::transaction(function () use ($source, $data): ConnectorSchemaSource {
            $lockedDefinition = $this->lockDefinition(
                ConnectorDefinition::query()->findOrFail($source->connector_definition_id),
            );

            $lockedSource = ConnectorSchemaSource::query()
                ->whereKey($source->getKey())
                ->where('connector_definition_id', $lockedDefinition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('code', $data) && $data['code'] !== $lockedSource->code) {
                throw ValidationException::withMessages([
                    'code' => 'Код джерела не можна змінити.',
                ]);
            }

            if (
                array_key_exists('connector_definition_id', $data)
                && $data['connector_definition_id'] !== $lockedSource->connector_definition_id
            ) {
                throw ValidationException::withMessages([
                    'connector_definition_id' => 'Джерело не можна перенести до іншої платформи.',
                ]);
            }

            $normalize = $this->normalizeScalar(...);

            $currentData = [
                'label' => $lockedSource->label,
                'source_kind' => $normalize($lockedSource->source_kind),
                'acquisition_mode' => $normalize($lockedSource->acquisition_mode),
                'schema_scope' => $normalize($lockedSource->schema_scope),
                'reference_url' => $lockedSource->reference_url,
                'endpoint_path' => $lockedSource->endpoint_path,
                'schema_version' => $lockedSource->schema_version,
                'is_primary' => (bool) $lockedSource->is_primary,
                'verification_status' => $normalize($lockedSource->verification_status),
                'last_verified_at' => $lockedSource->last_verified_at,
                'notes' => $lockedSource->notes,
                'sort_order' => $lockedSource->sort_order,
            ];

            $incoming = array_map($normalize, Arr::only($data, array_keys($currentData)));

            if (array_key_exists('is_primary', $incoming)) {
                $incoming['is_primary'] = $this->normalizeBoolean($incoming['is_primary'], 'is_primary');
            }

            $finalData = array_replace($currentData, $incoming);

            $this->validateInvariants($finalData);

            if ($finalData['is_primary'] === true) {
                $this->unsetOtherPrimarySourcesWithinLockedTransaction(
                    $lockedDefinition,
                    $finalData['schema_scope'],
                    $lockedSource->getKey(),
                );
            }

            $lockedSource->fill($finalData);
            $lockedSource->save();

            $this->ensureActiveDefinitionHasQualifyingSourceOrDraft($lockedDefinition);

            return $lockedSource->refresh();
        });
    }

    public function deleteSource(ConnectorSchemaSource $source): void
    {
        DB::transaction(function () use ($source): void {
            $lockedDefinition = $this->lockDefinition(
                ConnectorDefinition::query()->findOrFail($source->connector_definition_id),
            );

            $lockedSource = ConnectorSchemaSource::query()
                ->whereKey($source->getKey())
                ->where('connector_definition_id', $lockedDefinition->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedSource->delete();

            $this->ensureActiveDefinitionHasQualifyingSourceOrDraft($lockedDefinition);
        });
    }

    public function deleteDefinitionWhenUnreferenced(ConnectorDefinition $definition): void
    {
        DB::transaction(function () use ($definition): void {
            $lockedDefinition = $this->lockDefinition($definition);

            if ($lockedDefinition->schemaSources()->exists()) {
                throw ValidationException::withMessages([
                    'delete' => 'Неможливо видалити платформу з джерелами схеми.',
                ]);
            }

            $lockedDefinition->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateInvariants(array $data): void
    {
        $normalize = $this->normalizeScalar(...);

        $sourceKind = $normalize($data['source_kind'] ?? null);
        $schemaScope = $normalize($data['schema_scope'] ?? null);
        $acquisitionMode = $normalize($data['acquisition_mode'] ?? null);
        $endpointPath = $data['endpoint_path'] ?? null;
        $referenceUrl = $data['reference_url'] ?? null;
        $verificationStatus = $normalize($data['verification_status'] ?? null);
        $lastVerifiedAt = $data['last_verified_at'] ?? null;

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

        if ($verificationStatus === ConnectorSchemaVerificationStatus::Verified->value
            && ($lastVerifiedAt === null || $lastVerifiedAt === '')) {
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

    private function lockDefinition(ConnectorDefinition $definition): ConnectorDefinition
    {
        return ConnectorDefinition::query()
            ->whereKey($definition->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function unsetOtherPrimarySourcesWithinLockedTransaction(
        ConnectorDefinition $lockedDefinition,
        string $schemaScope,
        ?string $exceptSourceId = null,
    ): void {
        ConnectorSchemaSource::query()
            ->where('connector_definition_id', $lockedDefinition->getKey())
            ->where('schema_scope', $schemaScope)
            ->when($exceptSourceId, fn ($query) => $query->where('id', '!=', $exceptSourceId))
            ->update(['is_primary' => false]);
    }

    private function ensureActiveDefinitionHasQualifyingSourceOrDraft(ConnectorDefinition $lockedDefinition): void
    {
        $normalize = $this->normalizeScalar(...);
        $status = $normalize($lockedDefinition->status);

        if ($status !== ConnectorDefinitionStatus::Active->value) {
            return;
        }

        if ($lockedDefinition->hasVerifiedGlobalPrimarySource()) {
            return;
        }

        $lockedDefinition->status = ConnectorDefinitionStatus::Draft;
        $lockedDefinition->save();
    }

    private function normalizeScalar(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function normalizeBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        throw ValidationException::withMessages([
            $field => 'Поле повинно мати логічне значення.',
        ]);
    }
}
