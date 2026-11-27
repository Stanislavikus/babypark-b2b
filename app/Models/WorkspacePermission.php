<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WorkspacePermission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'code',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkspaceRole::class,
            'workspace_role_permissions',
            'workspace_permission_id',
            'workspace_role_id',
        )->withPivot('workspace_id');
    }
}
