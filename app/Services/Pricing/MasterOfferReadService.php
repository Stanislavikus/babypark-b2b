<?php

namespace App\Services\Pricing;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Workspace;

final class MasterOfferReadService
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly WorkspaceTaxDefaults $taxDefaults,
    ) {}

    /**
     * @return array{
     *   editable:bool,
     *   state:string,
     *   sell_net:?string,
     *   compare_at_net:?string,
     *   cost_net:?string,
     *   sell_gross:?string,
     *   compare_at_gross:?string,
     *   currency:string,
     *   effective_vat_rate:?string,
     *   expected_item_id:?string,
     *   expected_regular_net:?string,
     *   expected_sale_net:?string,
     *   expected_cost_net:?string,
     *   message:string
     * }
     */
    public function state(ProductVariant $variant): array
    {
        $workspace = Workspace::query()->findOrFail($variant->workspace_id);
        $sourceOwned = filled($variant->onec_guid);

        $lists = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->get();

        if ($lists->count() !== 1) {
            return $this->result(
                editable: false,
                state: 'default_price_list_unavailable',
                variant: $variant,
                currency: 'UAH',
                message: 'Основний прайс компанії недоступний або налаштований неоднозначно.',
            );
        }

        /** @var PriceList $priceList */
        $priceList = $lists->sole();
        $item = PriceListItem::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('price_list_id', $priceList->id)
            ->where('product_variant_id', $variant->id)
            ->where('quantity_min', 1)
            ->first();

        if ($item instanceof PriceListItem) {
            if (
                $item->status !== PriceListItemStatus::Active
                || $item->valid_from !== null
                || $item->valid_until !== null
            ) {
                return $this->result(
                    editable: false,
                    state: 'advanced_price_list_state',
                    variant: $variant,
                    currency: $priceList->currency,
                    item: $item,
                    workspace: $workspace,
                    message: 'Ціна керується розширеним правилом прайс-листа і в Master доступна лише для перегляду.',
                );
            }

            if (
                $item->sale_price !== null
                && bccomp((string) $item->sale_price, (string) $item->price, 2) >= 0
            ) {
                return $this->result(
                    editable: false,
                    state: 'legacy_invalid_sale_price',
                    variant: $variant,
                    currency: $priceList->currency,
                    item: $item,
                    workspace: $workspace,
                    message: 'Акційна ціна потребує перевірки у прайс-листі перед редагуванням у Master.',
                );
            }

            return $this->result(
                editable: ! $sourceOwned,
                state: $sourceOwned ? 'source_owned_read_only' : 'editable',
                variant: $variant,
                currency: $priceList->currency,
                item: $item,
                workspace: $workspace,
                message: $sourceOwned
                    ? 'Для варіанта з джерелом 1С ціна в Master доступна лише для перегляду.'
                    : 'Ціну можна редагувати.',
            );
        }

        $sell = null;
        $gross = null;
        $vatRate = $this->taxDefaults->resolveWorkspaceRate($workspace);

        try {
            $resolved = $this->priceResolver->resolveDefault($variant);
            $sell = $this->money($resolved->effectiveNetPrice);
            $gross = $this->money($resolved->grossPrice);
            $vatRate = $resolved->vatRate;
        } catch (PriceNotAvailableException|PriceListConfigurationException) {
            // A missing default-list item is a valid draft state. The editor can materialize it.
        }

        return [
            'editable' => ! $sourceOwned,
            'state' => $sourceOwned ? 'source_owned_read_only' : 'ready_to_materialize',
            'sell_net' => $sell,
            'compare_at_net' => null,
            'cost_net' => $variant->cost_price !== null ? $this->money($variant->cost_price) : null,
            'sell_gross' => $gross,
            'compare_at_gross' => null,
            'currency' => $priceList->currency,
            'effective_vat_rate' => $this->money($vatRate),
            'expected_item_id' => null,
            'expected_regular_net' => null,
            'expected_sale_net' => null,
            'expected_cost_net' => $variant->cost_price !== null ? $this->money($variant->cost_price) : null,
            'message' => $sourceOwned
                ? 'Для варіанта з джерелом 1С ціна в Master доступна лише для перегляду.'
                : 'Після збереження буде створено базову ціну в основному прайсі компанії.',
        ];
    }

    /**
     * @return array{
     *   editable:bool,
     *   state:string,
     *   sell_net:?string,
     *   compare_at_net:?string,
     *   cost_net:?string,
     *   sell_gross:?string,
     *   compare_at_gross:?string,
     *   currency:string,
     *   effective_vat_rate:?string,
     *   expected_item_id:?string,
     *   expected_regular_net:?string,
     *   expected_sale_net:?string,
     *   expected_cost_net:?string,
     *   message:string
     * }
     */
    private function result(
        bool $editable,
        string $state,
        ProductVariant $variant,
        string $currency,
        string $message,
        ?PriceListItem $item = null,
        ?Workspace $workspace = null,
    ): array {
        $regular = $item?->price !== null ? $this->money($item->price) : null;
        $sale = $item?->sale_price !== null ? $this->money($item->sale_price) : null;
        $sell = $sale ?? $regular;
        $compareAt = $sale !== null && $regular !== null && bccomp($sale, $regular, 2) < 0
            ? $regular
            : null;

        $vatRate = null;
        $sellGross = null;
        $compareGross = null;

        if ($workspace instanceof Workspace) {
            $vatRateFloat = $this->taxDefaults->resolveItemRate($item?->vat_rate, $workspace);
            $vatRate = $this->money($vatRateFloat);

            if ($sell !== null) {
                $sellGross = $this->money((float) $sell * (1 + $vatRateFloat / 100));
            }

            if ($compareAt !== null) {
                $compareGross = $this->money((float) $compareAt * (1 + $vatRateFloat / 100));
            }
        }

        return [
            'editable' => $editable,
            'state' => $state,
            'sell_net' => $sell,
            'compare_at_net' => $compareAt,
            'cost_net' => $variant->cost_price !== null ? $this->money($variant->cost_price) : null,
            'sell_gross' => $sellGross,
            'compare_at_gross' => $compareGross,
            'currency' => $currency,
            'effective_vat_rate' => $vatRate,
            'expected_item_id' => $item?->id,
            'expected_regular_net' => $regular,
            'expected_sale_net' => $sale,
            'expected_cost_net' => $variant->cost_price !== null ? $this->money($variant->cost_price) : null,
            'message' => $message,
        ];
    }

    private function money(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
