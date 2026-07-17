<?php

namespace App\Models;

use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDirection;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectorDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'direction',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ConnectorDirection::class,
            'status' => ConnectorDefinitionStatus::class,
        ];
    }

    public function schemaSources(): HasMany
    {
        return $this->hasMany(ConnectorSchemaSource::class)->orderBy('sort_order');
    }

    public function hasVerifiedGlobalPrimarySource(): bool
    {
        return $this->schemaSources()
            ->where('is_primary', true)
            ->where('schema_scope', ConnectorSchemaScope::Global)
            ->where('verification_status', ConnectorSchemaVerificationStatus::Verified)
            ->exists();
    }
}
