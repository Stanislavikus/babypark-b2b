<?php

namespace App\Models;

use App\Enums\AdobeProductCategoryAssignmentState;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdobeProductCategoryAssignment extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'external_record_link_id',
        'external_category_id',
        'state',
        'anchor_entity_id',
        'attempt_dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => AdobeProductCategoryAssignmentState::class,
            'attempt_dispatched_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class);
    }

    public function externalRecordLink(): BelongsTo
    {
        return $this->belongsTo(ExternalRecordLink::class);
    }
}
