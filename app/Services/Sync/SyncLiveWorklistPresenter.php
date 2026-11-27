<?php

namespace App\Services\Sync;

use App\Enums\SyncLiveOutcome;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncRunItem;
use App\Support\Sync\Preview\Presentation\SyncPreviewProductIdentityPresenter;
use Illuminate\Support\Collection;

final class SyncLiveWorklistPresenter
{
    public function __construct(
        private readonly SyncPreviewProductIdentityPresenter $identityPresenter,
    ) {}

    /**
     * @param  iterable<int, SyncRunItem>  $items
     * @return list<array<string, mixed>>
     */
    public function presentRows(iterable $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            $product = $item->product;

            if (! $product instanceof Product) {
                continue;
            }

            $outcome = $item->liveOutcome();

            $rows[] = [
                'identity_html' => $this->identityPresenter->presentHtml(
                    $product,
                    $this->sellableVariants($product),
                ),
                'outcome_label' => $this->outcomeLabel($outcome),
                'outcome_color' => $this->outcomeColor($outcome),
                'guidance' => $this->guidanceForOutcome($outcome),
            ];
        }

        return $rows;
    }

    private function outcomeLabel(SyncLiveOutcome $outcome): string
    {
        return match ($outcome) {
            SyncLiveOutcome::Synchronized => __('sync_live.outcomes.synchronized'),
            SyncLiveOutcome::NotApplied => __('sync_live.outcomes.not_applied'),
            SyncLiveOutcome::Partial => __('sync_live.outcomes.partial'),
            SyncLiveOutcome::Ambiguous => __('sync_live.outcomes.ambiguous'),
        };
    }

    private function outcomeColor(SyncLiveOutcome $outcome): string
    {
        return match ($outcome) {
            SyncLiveOutcome::Synchronized => 'success',
            SyncLiveOutcome::NotApplied => 'gray',
            SyncLiveOutcome::Partial => 'warning',
            SyncLiveOutcome::Ambiguous => 'danger',
        };
    }

    private function guidanceForOutcome(SyncLiveOutcome $outcome): ?string
    {
        return match ($outcome) {
            SyncLiveOutcome::NotApplied => __('sync_live.guidance.not_applied'),
            SyncLiveOutcome::Partial => __('sync_live.guidance.partial'),
            SyncLiveOutcome::Ambiguous => __('sync_live.guidance.ambiguous'),
            SyncLiveOutcome::Synchronized => null,
        };
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    private function sellableVariants(Product $product): Collection
    {
        return $product->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->is_active)
            ->values();
    }
}
