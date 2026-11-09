<?php

namespace App\Support\Workspace;

use App\Enums\AttributeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToWorkspaceOrGlobal
{
    public static function bootBelongsToWorkspaceOrGlobal(): void
    {
        static::addGlobalScope(new WorkspaceOrGlobalScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('scope') === AttributeScope::WorkspaceCustom->value
                && $model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', app(WorkspaceContext::class)->id());
            }
        });
    }

    public static function withoutWorkspaceScope(): Builder
    {
        return static::withoutGlobalScope(WorkspaceOrGlobalScope::class);
    }
}
