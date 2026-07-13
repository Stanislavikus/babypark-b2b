<?php

namespace App\Services\Pricing\Inspection;

use App\Filament\Pages\PriceInspector;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\ProductResource;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\Resolution\PriceResolutionSource;
use App\Services\Pricing\Resolution\PriceResolutionStep;
use App\Support\Workspace\WorkspaceContext;

final class PriceInspectorActionResolver
{
    public function forStep(
        PriceResolutionStep $step,
        PriceInspectorContext $context,
    ): ?PriceInspectorAction {
        $workspaceId = app(WorkspaceContext::class)->id();

        return match ($step->reason) {
            PriceResolutionReason::Expired,
            PriceResolutionReason::NotYetEffective,
            PriceResolutionReason::ItemInactive => $this->priceListItemAction($step, $context, $workspaceId),

            PriceResolutionReason::ItemMissing => $this->itemMissingAction($step, $context, $workspaceId),

            PriceResolutionReason::PriceListNotAssigned => $this->assignPriceListAction($context, $workspaceId),

            PriceResolutionReason::PriceListInactive => $this->openPriceListAction($step, $workspaceId),

            PriceResolutionReason::QuantityBelowMinimum => $this->checkQuantityAction($step, $context),

            PriceResolutionReason::DefaultPriceListMisconfigured => $this->openPriceListSettingsAction($context),

            PriceResolutionReason::Matched,
            PriceResolutionReason::PreviousSourceResolved,
            PriceResolutionReason::AllSourcesExhausted => null,
        };
    }

    private function priceListItemAction(
        PriceResolutionStep $step,
        PriceInspectorContext $context,
        string $workspaceId,
    ): ?PriceInspectorAction {
        $priceList = $this->findPriceList($step->priceListId, $workspaceId);

        if ($priceList === null || ! PriceListResource::canEdit($priceList)) {
            return null;
        }

        return new PriceInspectorAction(
            label: $step->reason === PriceResolutionReason::Expired
                ? __('price_inspector.action.extend_validity')
                : __('price_inspector.action.open_price_list_item'),
            url: PriceListResource::getUrl('edit', ['record' => $priceList]),
            deduplicationKey: 'price_list:'.$priceList->id,
        );
    }

    private function itemMissingAction(
        PriceResolutionStep $step,
        PriceInspectorContext $context,
        string $workspaceId,
    ): ?PriceInspectorAction {
        return match ($step->source) {
            PriceResolutionSource::CustomerPriceList => $this->addToPriceListAction(
                $step,
                $workspaceId,
                __('price_inspector.action.add_item_to_customer_price_list'),
            ),
            PriceResolutionSource::WorkspaceDefaultPriceList => $this->addToPriceListAction(
                $step,
                $workspaceId,
                __('price_inspector.action.add_item_to_default_price_list'),
            ),
            PriceResolutionSource::BasePriceCache => $this->setBasePriceAction($context, $workspaceId),
        };
    }

    private function addToPriceListAction(
        PriceResolutionStep $step,
        string $workspaceId,
        string $label,
    ): ?PriceInspectorAction {
        $priceList = $this->findPriceList($step->priceListId, $workspaceId);

        if ($priceList === null || ! PriceListResource::canEdit($priceList)) {
            return null;
        }

        return new PriceInspectorAction(
            label: $label,
            url: PriceListResource::getUrl('edit', ['record' => $priceList]),
            deduplicationKey: 'price_list:'.$priceList->id.':add_item',
        );
    }

    private function assignPriceListAction(
        PriceInspectorContext $context,
        string $workspaceId,
    ): ?PriceInspectorAction {
        $customer = $this->findCustomer($context->customer->id, $workspaceId);

        if ($customer === null || ! CustomerResource::canEdit($customer)) {
            return null;
        }

        return new PriceInspectorAction(
            label: __('price_inspector.action.assign_price_list'),
            url: CustomerResource::getUrl('edit', ['record' => $customer]),
            deduplicationKey: 'customer:'.$customer->id.':assign',
        );
    }

    private function openPriceListAction(
        PriceResolutionStep $step,
        string $workspaceId,
    ): ?PriceInspectorAction {
        $priceList = $this->findPriceList($step->priceListId, $workspaceId);

        if ($priceList === null || ! PriceListResource::canEdit($priceList)) {
            return null;
        }

        return new PriceInspectorAction(
            label: __('price_inspector.action.open_price_list'),
            url: PriceListResource::getUrl('edit', ['record' => $priceList]),
            deduplicationKey: 'price_list:'.$priceList->id,
        );
    }

    private function checkQuantityAction(
        PriceResolutionStep $step,
        PriceInspectorContext $context,
    ): ?PriceInspectorAction {
        $minQuantity = $step->metadata['quantity_min'] ?? null;

        if ($minQuantity === null) {
            return null;
        }

        $quantity = (int) $minQuantity;

        return new PriceInspectorAction(
            label: __('price_inspector.action.check_quantity', ['quantity' => $quantity]),
            url: PriceInspector::getUrl([
                'customer_id' => $context->customer->id,
                'variant_id' => $context->variant->id,
                'product_id' => $context->variant->product_id,
                'quantity' => $quantity,
                'effective_at' => $context->effectiveAt->toIso8601String(),
            ]),
            deduplicationKey: 'inspector:qty:'.$quantity,
        );
    }

    private function setBasePriceAction(
        PriceInspectorContext $context,
        string $workspaceId,
    ): ?PriceInspectorAction {
        $product = $this->findProduct($context->variant->product_id, $workspaceId);

        if ($product === null || ! ProductResource::canEdit($product)) {
            return null;
        }

        return new PriceInspectorAction(
            label: __('price_inspector.action.set_base_price'),
            url: ProductResource::getUrl('edit', ['record' => $product]),
            deduplicationKey: 'product:'.$product->id.':base_price',
        );
    }

    private function openPriceListSettingsAction(PriceInspectorContext $context): ?PriceInspectorAction
    {
        if (! PriceListResource::canViewAny()) {
            return null;
        }

        return new PriceInspectorAction(
            label: __('price_inspector.action.open_price_list_settings'),
            url: PriceListResource::getUrl('index'),
            deduplicationKey: 'price_lists:index',
        );
    }

    private function findPriceList(?string $priceListId, string $workspaceId): ?PriceList
    {
        if ($priceListId === null) {
            return null;
        }

        return PriceList::query()
            ->where('workspace_id', $workspaceId)
            ->find($priceListId);
    }

    private function findCustomer(int $customerId, string $workspaceId): ?Customer
    {
        return Customer::query()
            ->where('workspace_id', $workspaceId)
            ->find($customerId);
    }

    private function findProduct(int $productId, string $workspaceId): ?Product
    {
        return Product::query()
            ->where('workspace_id', $workspaceId)
            ->find($productId);
    }
}
