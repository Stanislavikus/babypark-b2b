<?php

namespace App\Support\Workspace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', app(WorkspaceContext::class)->id());
            }
        });
    }

    public static function withoutWorkspaceScope(): Builder
    {
        return static::withoutGlobalScope(WorkspaceScope::class);
    }
}
