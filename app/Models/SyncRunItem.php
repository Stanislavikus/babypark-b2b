<?php

namespace App\Models;

use App\Enums\SyncLiveOutcome;
use App\Enums\SyncPreviewOutcome;
use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

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
        $value = (string) $this->attributes['outcome'];

        if (! in_array($value, array_column(SyncPreviewOutcome::cases(), 'value'), true)) {
            throw new InvalidArgumentException("Outcome '{$value}' is not a preview outcome.");
        }

        return SyncPreviewOutcome::from($value);
    }

    public function liveOutcome(): SyncLiveOutcome
    {
        $value = (string) $this->attributes['outcome'];

        if (! in_array($value, array_column(SyncLiveOutcome::cases(), 'value'), true)) {
            throw new InvalidArgumentException("Outcome '{$value}' is not a live outcome.");
        }

        return SyncLiveOutcome::from($value);
    }
}
