<?php

namespace App\Services\Pricing;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\MasterOfferMutationException;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Workspace\WorkspacePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class MasterOfferMutationService
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function setPrice(
        User $actor,
        Workspace $workspace,
        Product $product,
        ProductVariant $variant,
        ?string $expectedItemId,
        ?string $expectedRegularNet,
        ?string $expectedSaleNet,
        string|float|int $sellNet,
        string|float|int|null $compareAtNet,
    ): PriceListItem {
        $sell = $this->normalizeRequiredMoney($sellNet);
        $compareAt = $this->normalizeNullableMoney($compareAtNet);

        if (bccomp($sell, '0.00', 2) <= 0) {
            throw MasterOfferMutationException::invalidSellPrice();
        }

        if ($compareAt !== null && bccomp($compareAt, $sell, 2) <= 0) {
            throw MasterOfferMutationException::invalidCompareAtPrice();
        }

        $regular = $compareAt ?? $sell;
        $sale = $compareAt !== null ? $sell : null;

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $product,
            $variant,
            $expectedItemId,
            $expectedRegularNet,
            $expectedSaleNet,
            $regular,
            $sale,
        ): PriceListItem {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->authorization->allows($actor, $lockedWorkspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            $lockedProduct = Product::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (filled($lockedProduct->onec_guid)) {
                throw MasterOfferMutationException::sourceOwnedReadOnly();
            }

            $lockedVariant = ProductVariant::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('product_id', $lockedProduct->id)
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedVariant instanceof ProductVariant) {
                throw MasterOfferMutationException::variantUnavailable();
            }

            $priceLists = PriceList::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('is_default', true)
                ->where('status', PriceListStatus::Active)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($priceLists->count() !== 1) {
                throw MasterOfferMutationException::defaultPriceListUnavailable();
            }

            /** @var PriceList $priceList */
            $priceList = $priceLists->sole();

            $item = PriceListItem::withoutWorkspaceScope()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('price_list_id', $priceList->id)
                ->where('product_variant_id', $lockedVariant->id)
                ->where('quantity_min', 1)
                ->lockForUpdate()
                ->first();

            $this->assertExpectedState(
                $item,
                $expectedItemId,
                $expectedRegularNet,
                $expectedSaleNet,
            );

            if ($item instanceof PriceListItem) {
                if (
                    $item->status !== PriceListItemStatus::Active
                    || $item->valid_from !== null
                    || $item->valid_until !== null
                ) {
                    throw MasterOfferMutationException::advancedPriceListState();
                }

                if (
                    $item->sale_price !== null
                    && bccomp((string) $item->sale_price, (string) $item->price, 2) >= 0
                ) {
                    throw MasterOfferMutationException::legacyInvalidSalePrice();
                }

                $item->update([
                    'price' => $regular,
                    'sale_price' => $sale,
                ]);

                return $item->refresh();
            }

            return PriceListItem::withoutWorkspaceScope()->create([
                'workspace_id' => $lockedWorkspace->id,
                'price_list_id' => $priceList->id,
                'product_variant_id' => $lockedVariant->id,
                'quantity_min' => 1,
                'price' => $regular,
                'sale_price' => $sale,
                'vat_rate' => null,
                'valid_from' => null,
                'valid_until' => null,
                'status' => PriceListItemStatus::Active,
            ]);
        }, 3);
    }

    private function assertExpectedState(
        ?PriceListItem $item,
        ?string $expectedItemId,
        ?string $expectedRegularNet,
        ?string $expectedSaleNet,
    ): void {
        if ($expectedItemId === null) {
            if ($item !== null) {
                throw MasterOfferMutationException::staleOffer();
            }

            return;
        }

        if (! $item instanceof PriceListItem || (string) $item->id !== $expectedItemId) {
            throw MasterOfferMutationException::staleOffer();
        }

        if (! $this->sameMoney($item->price, $expectedRegularNet)) {
            throw MasterOfferMutationException::staleOffer();
        }

        if (! $this->sameMoney($item->sale_price, $expectedSaleNet)) {
            throw MasterOfferMutationException::staleOffer();
        }
    }

    private function sameMoney(float|int|string|null $current, ?string $expected): bool
    {
        if ($current === null || $expected === null) {
            return $current === null && $expected === null;
        }

        return bccomp((string) $current, $expected, 2) === 0;
    }

    private function normalizeRequiredMoney(string|float|int $value): string
    {
        if (! is_numeric($value)) {
            throw MasterOfferMutationException::invalidSellPrice();
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeNullableMoney(string|float|int|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw MasterOfferMutationException::invalidCompareAtPrice();
        }

        return number_format((float) $value, 2, '.', '');
    }
}
