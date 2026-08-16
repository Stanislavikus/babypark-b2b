<?php

namespace App\Models;

use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncSemanticOperation;
use App\Support\Sync\Exceptions\SyncExternalContextValidationException;
use App\Support\Sync\SyncExternalContext;
use App\Support\Sync\SyncOperationSet;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncConfiguration extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'data_domain',
        'external_context',
        'enabled_operations',
        'operational_state',
        'configuration_revision',
    ];

    protected static function booted(): void
    {
        static::saving(function (SyncConfiguration $configuration): void {
            $payload = $configuration->getAttribute('external_context');

            if (! is_array($payload)) {
                throw SyncExternalContextValidationException::invalidPayload(
                    'External context must be a JSON object.',
                );
            }

            $context = SyncExternalContext::fromPayload($payload);

            $configuration->setAttribute('external_context', $context->payload());
            $configuration->setAttribute('external_context_key', $context->uniquenessKey());
        });
    }

    protected function casts(): array
    {
        return [
            'data_domain' => SyncDataDomain::class,
            'external_context' => 'array',
            'enabled_operations' => 'array',
            'operational_state' => SyncConfigurationOperationalState::class,
        ];
    }

    public function enabledOperationSet(): SyncOperationSet
    {
        /** @var list<string> $values */
        $values = $this->enabled_operations ?? [];

        return SyncOperationSet::fromOperations(
            array_map(
                static fn (string $operation): SyncSemanticOperation => SyncSemanticOperation::from($operation),
                $values,
            ),
        );
    }

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(FieldMapping::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
