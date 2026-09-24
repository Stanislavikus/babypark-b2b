<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdobeProductCategory extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'external_category_id',
        'parent_external_category_id',
        'name',
        'provider_path',
        'breadcrumb',
        'level',
        'position',
        'is_active',
        'first_seen_at',
        'last_seen_at',
        'missing_since',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
        ];
    }

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class);
    }
}
