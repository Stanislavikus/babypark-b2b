<?php

namespace App\Services\Sync;

use App\Enums\SyncLiveOutcome;
use App\Enums\SyncLiveWorklistFilter;
use App\Enums\SyncRunMode;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSemanticOperation;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class SyncLiveWorklistQuery
{
    public function baseQuery(Workspace $workspace, SyncRun $run): Builder
    {
        if (! $this->isWorklistRenderable($run)) {
            throw new \InvalidArgumentException('Live worklist requires a completed Live export run.');
        }

        return SyncRunItem::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('sync_run_id', $run->id)
            ->whereHas('product', fn (Builder $query) => $query->where('workspace_id', $workspace->id));
    }

    public function applyOutcomeFilter(Builder $query, SyncLiveWorklistFilter $filter): Builder
    {
        return match ($filter) {
            SyncLiveWorklistFilter::NeedsAttention => $query->whereIn('outcome', [
                SyncLiveOutcome::NotApplied,
                SyncLiveOutcome::Partial,
                SyncLiveOutcome::Ambiguous,
            ]),
            SyncLiveWorklistFilter::NotApplied => $query->where('outcome', SyncLiveOutcome::NotApplied),
            SyncLiveWorklistFilter::Partial => $query->where('outcome', SyncLiveOutcome::Partial),
            SyncLiveWorklistFilter::Ambiguous => $query->where('outcome', SyncLiveOutcome::Ambiguous),
            SyncLiveWorklistFilter::Synchronized => $query->where('outcome', SyncLiveOutcome::Synchronized),
            SyncLiveWorklistFilter::All => $query,
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
        return $run->mode === SyncRunMode::Live
            && $run->semantic_operation === SyncSemanticOperation::Export
            && $run->status === SyncRunStatus::Completed;
    }
}
