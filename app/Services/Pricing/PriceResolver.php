<?php

namespace App\Services\Pricing;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\InvalidPriceQuantityException;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Workspace;
use App\Services\Pricing\Resolution\PriceResolutionDiagnosticCollector;
use App\Services\Pricing\Resolution\PriceResolutionFailure;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\Resolution\PriceResolutionResult;
use App\Services\Pricing\Resolution\PriceResolutionSource;
use App\Services\Pricing\Resolution\PriceResolutionStep;
use App\Services\Pricing\Resolution\PriceResolutionStepStatus;
use App\Services\Pricing\Resolution\PriceResolutionTrace;
use App\Services\Pricing\Resolution\ResolutionMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PriceResolver
{
    private static int $standardResolutionExecutions = 0;

    /** @var array<string, Workspace> */
    private array $workspaceCache = [];

    public function __construct(
        private readonly WorkspaceTaxDefaults $taxDefaults,
    ) {}

    public static function resetStandardResolutionExecutions(): void
    {
        self::$standardResolutionExecutions = 0;
    }

    public static function standardResolutionExecutions(): int
    {
        return self::$standardResolutionExecutions;
    }

    public function resolveForCustomer(
        ProductVariant $variant,
        Customer $customer,
        int $quantity,
        ?CarbonImmutable $effectiveAt = null,
        ?PriceResolutionSnapshot $snapshot = null,
    ): ResolvedPrice {
        if ($effectiveAt !== null && $snapshot !== null) {
            throw new InvalidArgumentException('Cannot pass both effectiveAt and snapshot.');
        }

        $this->assertPositiveQuantity($quantity);

        $at = $snapshot?->effectiveAt ?? $effectiveAt ?? CarbonImmutable::now();

        return $this->resolveStandardResult($variant, $customer, $quantity, $at, $snapshot)
            ->toResolvedPrice();
    }

    public function resolveDefault(
        ProductVariant $variant,
        int $quantity = 1,
        ?CarbonImmutable $effectiveAt = null,
        ?PriceResolutionSnapshot $snapshot = null,
    ): ResolvedPrice {
        if ($effectiveAt !== null && $snapshot !== null) {
            throw new InvalidArgumentException('Cannot pass both effectiveAt and snapshot.');
        }

        $this->assertPositiveQuantity($quantity);

        $at = $snapshot?->effectiveAt ?? $effectiveAt ?? CarbonImmutable::now();

        return $this->resolveStandardResult($variant, null, $quantity, $at, $snapshot)
            ->toResolvedPrice();
    }

    public function resolveWithTrace(
        ProductVariant $variant,
        Customer $customer,
        int $quantity,
        ?CarbonImmutable $effectiveAt = null,
    ): PriceResolutionResult {
        return $this->resolveInternal(
            variant: $variant,
            customer: $customer,
            quantity: $quantity,
            effectiveAt: $effectiveAt ?? CarbonImmutable::now(),
            mode: ResolutionMode::Diagnostic,
        );
    }

    private function resolveStandardResult(
        ProductVariant $variant,
        ?Customer $customer,
        int $quantity,
        CarbonImmutable $effectiveAt,
        ?PriceResolutionSnapshot $snapshot,
    ): PriceResolutionResult {
        $cacheKey = $snapshot !== null
            ? $this->buildCacheKey($customer, $variant, $quantity, $effectiveAt)
            : null;

        if ($cacheKey !== null && $snapshot->has($cacheKey)) {
            return $snapshot->get($cacheKey);
        }

        self::$standardResolutionExecutions++;

        $result = $this->executeStandardResolution($variant, $customer, $quantity, $effectiveAt);

        if ($cacheKey !== null) {
            $snapshot->put($cacheKey, $result);
        }

        return $result;
    }

    private function buildCacheKey(
        ?Customer $customer,
        ProductVariant $variant,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): string {
        $context = $customer !== null ? 'customer' : 'no-customer';
        $customerId = $customer?->id ?? 'no-customer';

        return implode('|', [
            $context,
            $variant->workspace_id,
            $customerId,
            (string) $variant->id,
            (string) $quantity,
            $effectiveAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ]);
    }

    private function executeStandardResolution(
        ProductVariant $variant,
        ?Customer $customer,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): PriceResolutionResult {
        if ($customer !== null) {
            $assignedResolved = $this->tryResolveFromCustomerPriceList($variant, $customer, $quantity, $effectiveAt);

            if ($assignedResolved !== null) {
                return PriceResolutionResult::resolved(
                    $assignedResolved,
                    [PriceResolutionReason::Matched],
                    new PriceResolutionTrace([]),
                );
            }
        }

        return $this->resolveWorkspaceDefaultOrCacheAsResult($variant, $quantity, $effectiveAt);
    }

    private function resolveInternal(
        ProductVariant $variant,
        ?Customer $customer,
        int $quantity,
        CarbonImmutable $effectiveAt,
        ResolutionMode $mode,
    ): PriceResolutionResult {
        $this->assertPositiveQuantity($quantity);

        if ($mode === ResolutionMode::Diagnostic) {
            return $this->resolveDiagnostic($variant, $customer, $quantity, $effectiveAt);
        }

        return $this->executeStandardResolution($variant, $customer, $quantity, $effectiveAt);
    }

    private function resolveDiagnostic(
        ProductVariant $variant,
        ?Customer $customer,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): PriceResolutionResult {
        $collector = new PriceResolutionDiagnosticCollector;

        if ($customer !== null) {
            $assignedResolved = $this->diagnoseCustomerPriceList(
                $variant,
                $customer,
                $quantity,
                $effectiveAt,
                $collector,
            );

            if ($assignedResolved !== null) {
                $collector->addNotCheckedSteps([
                    PriceResolutionSource::WorkspaceDefaultPriceList,
                    PriceResolutionSource::BasePriceCache,
                ]);

                return PriceResolutionResult::resolved(
                    $assignedResolved,
                    $collector->reasonCodes(),
                    $collector->buildTrace(),
                );
            }
        }

        return $this->diagnoseWorkspaceDefaultOrCache($variant, $quantity, $effectiveAt, $collector);
    }

    private function tryResolveFromCustomerPriceList(
        ProductVariant $variant,
        Customer $customer,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): ?ResolvedPrice {
        if ($customer->default_price_list_id === null) {
            return null;
        }

        $assignedList = PriceList::withoutWorkspaceScope()
            ->where('id', $customer->default_price_list_id)
            ->first();

        if ($assignedList === null || $assignedList->status !== PriceListStatus::Active) {
            return null;
        }

        $item = $this->matchingListItem($assignedList->id, $variant->id, $quantity, $effectiveAt);

        if ($item === null) {
            return null;
        }

        return $this->resolvedFromItem($item, PriceResolutionSource::CustomerPriceList->value);
    }

    private function diagnoseCustomerPriceList(
        ProductVariant $variant,
        Customer $customer,
        int $quantity,
        CarbonImmutable $effectiveAt,
        PriceResolutionDiagnosticCollector $collector,
    ): ?ResolvedPrice {
        $source = PriceResolutionSource::CustomerPriceList;

        if ($customer->default_price_list_id === null) {
            $collector->addStep(new PriceResolutionStep(
                source: $source,
                status: PriceResolutionStepStatus::Skipped,
                reason: PriceResolutionReason::PriceListNotAssigned,
            ));
            $collector->addReasonCode(PriceResolutionReason::PriceListNotAssigned);

            return null;
        }

        $assignedList = PriceList::withoutWorkspaceScope()
            ->where('id', $customer->default_price_list_id)
            ->first();

        if ($assignedList === null || $assignedList->status !== PriceListStatus::Active) {
            $collector->addStep(new PriceResolutionStep(
                source: $source,
                status: PriceResolutionStepStatus::Skipped,
                reason: PriceResolutionReason::PriceListInactive,
                priceListId: $customer->default_price_list_id,
            ));
            $collector->addReasonCode(PriceResolutionReason::PriceListInactive);

            return null;
        }

        return $this->diagnosePriceListItems(
            $assignedList,
            $variant,
            $quantity,
            $effectiveAt,
            $source,
            $collector,
        );
    }

    private function resolveWorkspaceDefaultOrCacheAsResult(
        ProductVariant $variant,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): PriceResolutionResult {
        $configurationFailure = $this->standardWorkspaceDefaultConfigurationFailure($variant);

        if ($configurationFailure !== null) {
            return $configurationFailure;
        }

        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->sole();

        $item = $this->matchingListItem($defaultList->id, $variant->id, $quantity, $effectiveAt);

        if ($item !== null) {
            return PriceResolutionResult::resolved(
                $this->resolvedFromItem($item, PriceResolutionSource::WorkspaceDefaultPriceList->value),
                [PriceResolutionReason::Matched],
                new PriceResolutionTrace([]),
            );
        }

        if ($variant->base_price_cache !== null) {
            $currency = $defaultList->currency ?: (string) config('pricing.default_currency', 'UAH');
            $amount = (float) $variant->base_price_cache;
            $vatRate = $this->taxDefaults->resolveWorkspaceRate($this->workspaceFor($variant->workspace_id));

            return PriceResolutionResult::resolved(
                ResolvedPrice::fromBasePriceCache($amount, $currency, $vatRate),
                [PriceResolutionReason::Matched],
                new PriceResolutionTrace([]),
            );
        }

        return PriceResolutionResult::unavailable(
            reasonCodes: [PriceResolutionReason::AllSourcesExhausted],
            trace: new PriceResolutionTrace([]),
            failure: new PriceResolutionFailure(
                reason: PriceResolutionReason::AllSourcesExhausted,
                message: "No price available for variant {$variant->id} at quantity {$quantity}.",
                context: [
                    'variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'workspace_id' => $variant->workspace_id,
                ],
            ),
        );
    }

    private function standardWorkspaceDefaultConfigurationFailure(ProductVariant $variant): ?PriceResolutionResult
    {
        $defaults = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->get();

        if ($defaults->isEmpty()) {
            $message = "Workspace {$variant->workspace_id} has no active default price list.";

            return PriceResolutionResult::configurationError(
                reasonCodes: [PriceResolutionReason::DefaultPriceListMisconfigured],
                trace: new PriceResolutionTrace([]),
                failure: new PriceResolutionFailure(
                    reason: PriceResolutionReason::DefaultPriceListMisconfigured,
                    message: $message,
                    context: ['workspace_id' => $variant->workspace_id],
                ),
            );
        }

        if ($defaults->count() > 1) {
            $message = "Workspace {$variant->workspace_id} has multiple active default price lists.";

            return PriceResolutionResult::configurationError(
                reasonCodes: [PriceResolutionReason::DefaultPriceListMisconfigured],
                trace: new PriceResolutionTrace([]),
                failure: new PriceResolutionFailure(
                    reason: PriceResolutionReason::DefaultPriceListMisconfigured,
                    message: $message,
                    context: ['workspace_id' => $variant->workspace_id],
                ),
            );
        }

        return null;
    }

    private function diagnoseWorkspaceDefaultOrCache(
        ProductVariant $variant,
        int $quantity,
        CarbonImmutable $effectiveAt,
        PriceResolutionDiagnosticCollector $collector,
    ): PriceResolutionResult {
        $configurationFailure = $this->diagnoseWorkspaceDefaultConfiguration($variant, $collector);

        if ($configurationFailure !== null) {
            return $configurationFailure;
        }

        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->sole();

        $workspaceResolved = $this->diagnosePriceListItems(
            $defaultList,
            $variant,
            $quantity,
            $effectiveAt,
            PriceResolutionSource::WorkspaceDefaultPriceList,
            $collector,
        );

        if ($workspaceResolved !== null) {
            $collector->addNotCheckedSteps([PriceResolutionSource::BasePriceCache]);

            return PriceResolutionResult::resolved(
                $workspaceResolved,
                $collector->reasonCodes(),
                $collector->buildTrace(),
            );
        }

        if ($variant->base_price_cache !== null) {
            $currency = $defaultList->currency ?: (string) config('pricing.default_currency', 'UAH');
            $amount = (float) $variant->base_price_cache;
            $vatRate = $this->taxDefaults->resolveWorkspaceRate($this->workspaceFor($variant->workspace_id));

            $collector->addStep(new PriceResolutionStep(
                source: PriceResolutionSource::BasePriceCache,
                status: PriceResolutionStepStatus::Matched,
                reason: PriceResolutionReason::Matched,
                amount: $amount,
                currency: $currency,
            ));
            $collector->addReasonCode(PriceResolutionReason::Matched);

            return PriceResolutionResult::resolved(
                ResolvedPrice::fromBasePriceCache($amount, $currency, $vatRate),
                $collector->reasonCodes(),
                $collector->buildTrace(),
            );
        }

        $collector->addStep(new PriceResolutionStep(
            source: PriceResolutionSource::BasePriceCache,
            status: PriceResolutionStepStatus::Failed,
            reason: PriceResolutionReason::ItemMissing,
        ));
        $collector->addReasonCode(PriceResolutionReason::ItemMissing);
        $collector->addReasonCode(PriceResolutionReason::AllSourcesExhausted);

        return PriceResolutionResult::unavailable(
            reasonCodes: $collector->reasonCodes(),
            trace: $collector->buildTrace(),
            failure: new PriceResolutionFailure(
                reason: PriceResolutionReason::AllSourcesExhausted,
                message: "No price available for variant {$variant->id} at quantity {$quantity}.",
                context: [
                    'variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'workspace_id' => $variant->workspace_id,
                ],
            ),
        );
    }

    private function diagnoseWorkspaceDefaultConfiguration(
        ProductVariant $variant,
        PriceResolutionDiagnosticCollector $collector,
    ): ?PriceResolutionResult {
        $defaults = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->where('status', PriceListStatus::Active)
            ->get();

        if ($defaults->isEmpty()) {
            $message = "Workspace {$variant->workspace_id} has no active default price list.";
            $collector->addReasonCode(PriceResolutionReason::DefaultPriceListMisconfigured);

            return PriceResolutionResult::configurationError(
                reasonCodes: $collector->reasonCodes(),
                trace: $collector->buildTrace(),
                failure: new PriceResolutionFailure(
                    reason: PriceResolutionReason::DefaultPriceListMisconfigured,
                    message: $message,
                    context: ['workspace_id' => $variant->workspace_id],
                ),
            );
        }

        if ($defaults->count() > 1) {
            $message = "Workspace {$variant->workspace_id} has multiple active default price lists.";
            $collector->addReasonCode(PriceResolutionReason::DefaultPriceListMisconfigured);

            return PriceResolutionResult::configurationError(
                reasonCodes: $collector->reasonCodes(),
                trace: $collector->buildTrace(),
                failure: new PriceResolutionFailure(
                    reason: PriceResolutionReason::DefaultPriceListMisconfigured,
                    message: $message,
                    context: ['workspace_id' => $variant->workspace_id],
                ),
            );
        }

        return null;
    }

    private function diagnosePriceListItems(
        PriceList $priceList,
        ProductVariant $variant,
        int $quantity,
        CarbonImmutable $effectiveAt,
        PriceResolutionSource $source,
        PriceResolutionDiagnosticCollector $collector,
    ): ?ResolvedPrice {
        /** @var Collection<int, PriceListItem> $items */
        $items = PriceListItem::withoutWorkspaceScope()
            ->where('price_list_id', $priceList->id)
            ->where('product_variant_id', $variant->id)
            ->orderByDesc('quantity_min')
            ->get();

        if ($items->isEmpty()) {
            $collector->addStep(new PriceResolutionStep(
                source: $source,
                status: PriceResolutionStepStatus::Skipped,
                reason: PriceResolutionReason::ItemMissing,
                priceListId: $priceList->id,
            ));
            $collector->addReasonCode(PriceResolutionReason::ItemMissing);

            return null;
        }

        $winner = null;

        foreach ($items as $item) {
            $evaluation = $this->evaluateCandidateItem($item, $quantity, $effectiveAt);

            if ($evaluation['passes']) {
                if ($winner === null) {
                    $winner = $item;
                    $collector->addStep(new PriceResolutionStep(
                        source: $source,
                        status: PriceResolutionStepStatus::Matched,
                        reason: PriceResolutionReason::Matched,
                        priceListId: $priceList->id,
                        priceListItemId: $item->id,
                        amount: (float) ($item->sale_price ?? $item->price),
                        currency: $priceList->currency ?: (string) config('pricing.default_currency', 'UAH'),
                        metadata: $evaluation['metadata'],
                    ));
                }

                continue;
            }

            $collector->addStep(new PriceResolutionStep(
                source: $source,
                status: PriceResolutionStepStatus::Skipped,
                reason: $evaluation['primary_reason'],
                priceListId: $priceList->id,
                priceListItemId: $item->id,
                metadata: $evaluation['metadata'],
            ));
        }

        if ($winner !== null) {
            $collector->addReasonCode(PriceResolutionReason::Matched);

            return $this->resolvedFromItem($winner, $source->value);
        }

        return null;
    }

    /**
     * @return array{passes: bool, primary_reason: PriceResolutionReason, metadata: array<string, mixed>}
     */
    private function evaluateCandidateItem(
        PriceListItem $item,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): array {
        $metadata = [
            'status' => $item->status->value,
            'quantity_min' => $item->quantity_min,
            'valid_from' => $item->valid_from?->toIso8601String(),
            'valid_until' => $item->valid_until?->toIso8601String(),
        ];

        if ($item->status !== PriceListItemStatus::Active) {
            return [
                'passes' => false,
                'primary_reason' => PriceResolutionReason::ItemInactive,
                'metadata' => $metadata,
            ];
        }

        if ($item->quantity_min > $quantity) {
            return [
                'passes' => false,
                'primary_reason' => PriceResolutionReason::QuantityBelowMinimum,
                'metadata' => $metadata,
            ];
        }

        if ($item->valid_from !== null && $item->valid_from->gt($effectiveAt)) {
            return [
                'passes' => false,
                'primary_reason' => PriceResolutionReason::NotYetEffective,
                'metadata' => $metadata,
            ];
        }

        if ($item->valid_until !== null && $item->valid_until->lt($effectiveAt)) {
            return [
                'passes' => false,
                'primary_reason' => PriceResolutionReason::Expired,
                'metadata' => $metadata,
            ];
        }

        return [
            'passes' => true,
            'primary_reason' => PriceResolutionReason::Matched,
            'metadata' => $metadata,
        ];
    }

    private function matchingListItem(
        string $priceListId,
        int $variantId,
        int $quantity,
        CarbonImmutable $effectiveAt,
    ): ?PriceListItem {
        return PriceListItem::withoutWorkspaceScope()
            ->where('price_list_id', $priceListId)
            ->where('product_variant_id', $variantId)
            ->where('status', PriceListItemStatus::Active)
            ->where('quantity_min', '<=', $quantity)
            ->where(function ($query) use ($effectiveAt): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $effectiveAt);
            })
            ->where(function ($query) use ($effectiveAt): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $effectiveAt);
            })
            ->orderByDesc('quantity_min')
            ->first();
    }

    private function resolvedFromItem(PriceListItem $item, string $source): ResolvedPrice
    {
        $item->loadMissing('priceList');

        $currency = $item->priceList?->currency
            ?: (string) config('pricing.default_currency', 'UAH');

        $workspace = $this->workspaceFor($item->workspace_id);
        $vatRate = $this->taxDefaults->resolveItemRate($item->vat_rate, $workspace);

        return ResolvedPrice::fromListItem(
            regularNetPrice: (float) $item->price,
            salePrice: $item->sale_price !== null ? (float) $item->sale_price : null,
            vatRate: $vatRate,
            currency: $currency,
            source: $source,
            sourcePriceListId: $item->price_list_id,
            sourcePriceListItemId: $item->id,
        );
    }

    private function workspaceFor(string $workspaceId): Workspace
    {
        return $this->workspaceCache[$workspaceId] ??= Workspace::query()->findOrFail($workspaceId);
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidPriceQuantityException(
                'Price resolution requires a quantity greater than zero.'
            );
        }
    }
}
