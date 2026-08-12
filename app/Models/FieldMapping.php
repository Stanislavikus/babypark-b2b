<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldMapping extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'sync_configuration_id',
        'field_binding_id',
        'external_field_key',
    ];

    public function syncConfiguration(): BelongsTo
    {
        return $this->belongsTo(SyncConfiguration::class);
    }

    public function fieldBinding(): BelongsTo
    {
        return $this->belongsTo(FieldBinding::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
