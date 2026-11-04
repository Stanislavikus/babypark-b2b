<?php

namespace App\Models;

use App\Enums\SyncDataDomain;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteCatalogCurrentSnapshot extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = ['workspace_id', 'connector_account_id', 'data_domain', 'snapshot_id'];

    protected function casts(): array
    {
        return ['data_domain' => SyncDataDomain::class];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(RemoteCatalogSnapshot::class, 'snapshot_id');
    }
}
