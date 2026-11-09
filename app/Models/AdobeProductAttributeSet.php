<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdobeProductAttributeSet extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'connector_account_id', 'connector_schema_source_id',
        'provider_attribute_set_id', 'name',
        'first_seen_at', 'last_seen_at', 'missing_since',
    ];

    protected function casts(): array
    {
        return [
            'provider_attribute_set_id' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function schemaSource(): BelongsTo
    {
        return $this->belongsTo(ConnectorSchemaSource::class, 'connector_schema_source_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(AdobeProductAttributeGroup::class, 'adobe_product_attribute_set_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AdobeProductAttributeSetMembership::class, 'adobe_product_attribute_set_id');
    }
}
