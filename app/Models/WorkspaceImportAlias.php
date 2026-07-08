<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceImportAlias extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'attribute_definition_id',
        'alias_name',
        'source',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class);
    }
}
