<?php

namespace App\Support\Workspace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceOrGlobalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $builder->where(function (Builder $query) use ($model, $workspaceId) {
            $query->where($model->qualifyColumn('workspace_id'), $workspaceId)
                ->orWhereNull($model->qualifyColumn('workspace_id'));
        });
    }
}
