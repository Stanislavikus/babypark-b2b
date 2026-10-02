<?php

namespace App\Services\Sync;

use App\Enums\SyncPreviewOutcome;
use App\Enums\SyncPreviewWorklistFilter;
use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class SyncPreviewWorklistQuery
{
    public function baseQuery(Workspace $workspace, SyncRun $run): Builder
    {
        return SyncRunItem::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('sync_run_id', $run->id)
            ->whereHas('product', fn (Builder $query) => $query->where('workspace_id', $workspace->id));
    }

    public function applyOutcomeFilter(Builder $query, SyncPreviewWorklistFilter $filter): Builder
    {
        return match ($filter) {
            SyncPreviewWorklistFilter::NeedsAttention => $query->whereIn('outcome', [
                SyncPreviewOutcome::Warning,
                SyncPreviewOutcome::Blocked,
            ]),
            SyncPreviewWorklistFilter::Blocked => $query->where('outcome', SyncPreviewOutcome::Blocked),
            SyncPreviewWorklistFilter::Warning => $query->where('outcome', SyncPreviewOutcome::Warning),
            SyncPreviewWorklistFilter::Ready => $query->where('outcome', SyncPreviewOutcome::Ready),
            SyncPreviewWorklistFilter::All => $query,
        };
    }

    public function applySearch(Builder $query, string $search): Builder
    {
        $term = trim($search);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->whereHas('product', function (Builder $productQuery) use ($like): void {
            $productQuery
                ->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhereHas('variants', function (Builder $variantQuery) use ($like): void {
                            $variantQuery
                                ->where('is_active', true)
                                ->where('sku', 'like', $like);
                        });
                });
        });
    }

    public function isWorklistRenderable(SyncRun $run): bool
    {
        return $run->status === SyncRunStatus::Completed;
    }
}
