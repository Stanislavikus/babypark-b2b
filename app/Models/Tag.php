<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'name',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->using(ProductTag::class)
            ->withPivot('workspace_id')
            ->withPivotValue('workspace_id', $this->relationWorkspaceId());
    }

    private function relationWorkspaceId(): string
    {
        return (string) ($this->getAttribute('workspace_id') ?? app(WorkspaceContext::class)->id());
    }
}
