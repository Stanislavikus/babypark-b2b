<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WorkspaceRole extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'name',
        'template_key',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkspacePermission::class,
            'workspace_role_permissions',
            'workspace_role_id',
            'workspace_permission_id',
        )->withPivot('workspace_id');
    }
}
