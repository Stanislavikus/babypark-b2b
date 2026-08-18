<?php

namespace App\Services\Sync;

use App\Enums\SyncPreviewOutcome;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sync\Preview\Presentation\SyncPreviewFindingPresenter;
use App\Support\Sync\Preview\Presentation\SyncPreviewProductIdentityPresenter;
use Illuminate\Support\Collection;

final class SyncPreviewWorklistPresenter
{
    public function __construct(
        private readonly SyncPreviewPresentationContextLoader $contextLoader,
        private readonly SyncPreviewFindingPresenter $findingPresenter,
        private readonly SyncPreviewProductIdentityPresenter $identityPresenter,
    ) {}

    /**
     * @param  Collection<int, SyncRunItem>  $items
     * @return list<array<string, mixed>>
     */
    public function presentRows(
        SyncRun $run,
        ?SyncConfiguration $configuration,
        string $accountId,
        User $actor,
        Workspace $workspace,
        Collection $items,
    ): array {
        if ($items->isEmpty()) {
            return [];
        }

        $context = $this->contextLoader->loadForRun(
            $actor,
            $workspace,
            $accountId,
            $configuration,
            $run,
            $items,
        );

        $productIds = $items->pluck('product_id')->unique()->all();
        $products = Product::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $productIds)
            ->with(['variants' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            if ($product === null) {
                continue;
            }

            $findings = [];

            foreach ($item->findings ?? [] as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $presentation = $this->findingPresenter->present($finding, $context, (string) $product->id);
                $findings[] = $presentation->toArray();
            }

            $attentionSummary = $this->summarizeAttention($findings);

            $rows[] = [
                'identity_html' => $this->identityPresenter->presentHtml($product, $product->variants),
                'outcome_label' => $this->outcomeLabel($item->outcome),
                'outcome_color' => $this->outcomeColor($item->outcome),
                'attention_summary' => $attentionSummary,
                'findings' => $findings,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function summarizeAttention(array $findings): string
    {
        if ($findings === []) {
            return __('sync_preview.worklist.no_findings');
        }

        return collect($findings)
            ->pluck('summary')
            ->filter(fn ($summary) => is_string($summary) && $summary !== '')
            ->take(2)
            ->implode('; ');
    }

    private function outcomeLabel(SyncPreviewOutcome $outcome): string
    {
        return match ($outcome) {
            SyncPreviewOutcome::Ready => __('sync_preview.outcomes.ready'),
            SyncPreviewOutcome::Warning => __('sync_preview.outcomes.warning'),
            SyncPreviewOutcome::Blocked => __('sync_preview.outcomes.blocked'),
        };
    }

    private function outcomeColor(SyncPreviewOutcome $outcome): string
    {
        return match ($outcome) {
            SyncPreviewOutcome::Ready => 'success',
            SyncPreviewOutcome::Warning => 'warning',
            SyncPreviewOutcome::Blocked => 'danger',
        };
    }
}
