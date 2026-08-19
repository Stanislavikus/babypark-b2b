<?php

namespace App\Models;

use App\Enums\SyncLiveOutcome;
use App\Enums\SyncPreviewOutcome;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRunItem extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'sync_run_id',
        'product_id',
        'outcome',
        'findings',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function previewOutcome(): SyncPreviewOutcome
    {
        return SyncPreviewOutcome::from((string) $this->attributes['outcome']);
    }

    public function liveOutcome(): SyncLiveOutcome
    {
        return SyncLiveOutcome::from((string) $this->attributes['outcome']);
    }
}
