<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdobeProductCategoryCatalogueState extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'category_count',
        'target_context',
        'last_successful_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'category_count' => 'integer',
            'target_context' => 'array',
            'last_successful_synced_at' => 'datetime',
        ];
    }

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class);
    }
}
