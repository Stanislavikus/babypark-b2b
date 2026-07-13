<?php

namespace Tests\Unit;

use App\Enums\PriceListItemStatus;
use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\PriceList;
use App\Models\Workspace;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\Resolution\PriceResolutionResult;
use App\Services\Pricing\Resolution\PriceResolutionSource;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use App\Services\Pricing\Resolution\PriceResolutionStep;
use App\Services\Pricing\Resolution\PriceResolutionStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceResolutionTraceTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private PriceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(PriceResolver::class);
    }

    public function test_customer_price_list_not_assigned(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $step = $this->findStep($result, PriceResolutionSource::CustomerPriceList);
        $this->assertSame(PriceResolutionStepStatus::Skipped, $step->status);
        $this->assertSame(PriceResolutionReason::PriceListNotAssigned, $step->reason);
    }

    public function test_customer_price_list_inactive(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $inactiveList = $this->createPriceList(status: PriceListStatus::Inactive);
        $customer->update(['default_price_list_id' => $inactiveList->id]);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $step = $this->findStep($result, PriceResolutionSource::CustomerPriceList);
        $this->assertSame(PriceResolutionStepStatus::Skipped, $step->status);
        $this->assertSame(PriceResolutionReason::PriceListInactive, $step->reason);
        $this->assertSame($inactiveList->id, $step->priceListId);
    }

    public function test_customer_list_item_missing_falls_back_to_workspace_default(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->firstOrFail();
        $this->createPriceListItem($defaultList, $variant, 55.50);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->assertSame(PriceResolutionStatus::Resolved, $result->status);
        $customerStep = $this->findStep($result, PriceResolutionSource::CustomerPriceList);
        $this->assertSame(PriceResolutionReason::ItemMissing, $customerStep->reason);
        $defaultStep = $this->findStep($result, PriceResolutionSource::WorkspaceDefaultPriceList);
        $this->assertSame(PriceResolutionStepStatus::Matched, $defaultStep->status);
        $this->assertSame(PriceResolutionReason::Matched, $defaultStep->reason);
    }

    public function test_workspace_default_item_missing_falls_back_to_base_cache(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant(basePriceCache: 42.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->assertSame(PriceResolutionStatus::Resolved, $result->status);
        $defaultStep = $this->findStep($result, PriceResolutionSource::WorkspaceDefaultPriceList);
        $this->assertSame(PriceResolutionReason::ItemMissing, $defaultStep->reason);
        $cacheStep = $this->findStep($result, PriceResolutionSource::BasePriceCache);
        $this->assertSame(PriceResolutionStepStatus::Matched, $cacheStep->status);
        $this->assertSame(42.0, $cacheStep->amount);
    }

    public function test_resolved_from_customer_price_list_has_not_checked_lower_sources(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->assertSame(PriceResolutionStatus::Resolved, $result->status);
        $customerStep = $this->findStep($result, PriceResolutionSource::CustomerPriceList);
        $this->assertSame(PriceResolutionStepStatus::Matched, $customerStep->status);

        $defaultStep = $this->findStep($result, PriceResolutionSource::WorkspaceDefaultPriceList);
        $this->assertSame(PriceResolutionStepStatus::NotChecked, $defaultStep->status);
        $this->assertSame(PriceResolutionReason::PreviousSourceResolved, $defaultStep->reason);

        $cacheStep = $this->findStep($result, PriceResolutionSource::BasePriceCache);
        $this->assertSame(PriceResolutionStepStatus::NotChecked, $cacheStep->status);
        $this->assertSame(PriceResolutionReason::PreviousSourceResolved, $cacheStep->reason);
    }

    public function test_resolved_from_workspace_default_has_not_checked_base_cache(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->firstOrFail();
        $this->createPriceListItem($defaultList, $variant, 55.50);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $defaultStep = $this->findStep($result, PriceResolutionSource::WorkspaceDefaultPriceList);
        $this->assertSame(PriceResolutionStepStatus::Matched, $defaultStep->status);

        $cacheStep = $this->findStep($result, PriceResolutionSource::BasePriceCache);
        $this->assertSame(PriceResolutionStepStatus::NotChecked, $cacheStep->status);
        $this->assertSame(PriceResolutionReason::PreviousSourceResolved, $cacheStep->reason);
    }

    public function test_resolved_from_base_price_cache(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant(basePriceCache: 42.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $cacheStep = $this->findStep($result, PriceResolutionSource::BasePriceCache);
        $this->assertSame(PriceResolutionStepStatus::Matched, $cacheStep->status);
        $this->assertSame(PriceResolutionReason::Matched, $cacheStep->reason);
        $this->assertSame(42.0, $result->price?->effectiveNetPrice);
    }

    public function test_multiple_candidate_items_each_get_own_skipped_step(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem($list, $variant, 100.00, quantityMin: 10);
        $this->createPriceListItem(
            $list,
            $variant,
            90.00,
            quantityMin: 1,
            validFrom: CarbonImmutable::parse('2026-08-01'),
        );
        $this->createPriceListItem(
            $list,
            $variant,
            90.00,
            quantityMin: 2,
            validUntil: CarbonImmutable::parse('2026-01-01'),
        );

        $result = $this->resolver->resolveWithTrace($variant, $customer, 5);

        $customerSteps = collect($result->trace->steps)
            ->filter(fn ($step) => $step->source === PriceResolutionSource::CustomerPriceList)
            ->values();

        $this->assertCount(3, $customerSteps);
        $this->assertTrue($customerSteps->contains(
            fn ($step) => $step->reason === PriceResolutionReason::QuantityBelowMinimum
        ));
        $this->assertTrue($customerSteps->contains(
            fn ($step) => $step->reason === PriceResolutionReason::NotYetEffective
        ));
        $this->assertTrue($customerSteps->contains(
            fn ($step) => $step->reason === PriceResolutionReason::Expired
        ));
    }

    public function test_primary_reason_item_inactive_when_multiple_failures(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem(
            $list,
            $variant,
            100.00,
            quantityMin: 10,
            status: PriceListItemStatus::Suspended,
            validUntil: CarbonImmutable::parse('2026-01-01'),
        );

        $result = $this->resolver->resolveWithTrace($variant, $customer, 5);

        $customerSteps = collect($result->trace->steps)
            ->filter(fn ($step) => $step->source === PriceResolutionSource::CustomerPriceList)
            ->values();

        $this->assertCount(1, $customerSteps);
        $this->assertSame(PriceResolutionReason::ItemInactive, $customerSteps->first()->reason);
        $this->assertArrayHasKey('status', $customerSteps->first()->metadata);
        $this->assertArrayHasKey('quantity_min', $customerSteps->first()->metadata);
        $this->assertArrayHasKey('valid_until', $customerSteps->first()->metadata);
    }

    public function test_missing_base_price_cache_results_in_unavailable(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->assertSame(PriceResolutionStatus::Unavailable, $result->status);
        $cacheStep = $this->findStep($result, PriceResolutionSource::BasePriceCache);
        $this->assertSame(PriceResolutionStepStatus::Failed, $cacheStep->status);
        $this->assertSame(PriceResolutionReason::ItemMissing, $cacheStep->reason);

        $this->assertSame(PriceResolutionReason::AllSourcesExhausted, $result->failure?->reason);
        $this->assertContains(PriceResolutionReason::ItemMissing, $result->reasonCodes);
        $this->assertContains(PriceResolutionReason::AllSourcesExhausted, $result->reasonCodes);

        $notCheckedSteps = collect($result->trace->steps)
            ->filter(fn ($step) => $step->status === PriceResolutionStepStatus::NotChecked);
        $this->assertCount(0, $notCheckedSteps);
    }

    public function test_default_price_list_misconfigured_zero_defaults(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = Workspace::query()->create([
            'name' => 'No Default Workspace',
            'is_default' => false,
        ]);
        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->assertSame(PriceResolutionStatus::ConfigurationError, $result->status);
        $this->assertSame(PriceResolutionReason::DefaultPriceListMisconfigured, $result->failure?->reason);
        $this->assertFalse(collect($result->trace->steps)->contains(
            fn ($step) => $step->source === PriceResolutionSource::WorkspaceDefaultPriceList
                || $step->source === PriceResolutionSource::BasePriceCache
        ));
    }

    public function test_default_price_list_misconfigured_multiple_defaults(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = $this->defaultWorkspace();
        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->createPriceList($workspace, isDefault: true);
        $this->createPriceList($workspace, isDefault: true);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->assertSame(PriceResolutionStatus::ConfigurationError, $result->status);
        $this->assertSame(PriceResolutionReason::DefaultPriceListMisconfigured, $result->failure?->reason);
        $this->assertFalse(collect($result->trace->steps)->contains(
            fn ($step) => $step->source === PriceResolutionSource::WorkspaceDefaultPriceList
                || $step->source === PriceResolutionSource::BasePriceCache
        ));
    }

    public function test_effective_at_affects_not_yet_effective_and_expired(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem(
            $list,
            $variant,
            100.00,
            validFrom: CarbonImmutable::parse('2026-08-01'),
        );

        $futureResult = $this->resolver->resolveWithTrace(
            $variant,
            $customer,
            1,
            CarbonImmutable::parse('2026-07-01'),
        );

        $futureStep = $this->findStep($futureResult, PriceResolutionSource::CustomerPriceList);
        $this->assertSame(PriceResolutionReason::NotYetEffective, $futureStep->reason);

        $this->createPriceListItem(
            $list,
            $variant,
            90.00,
            quantityMin: 2,
            validUntil: CarbonImmutable::parse('2026-06-01'),
        );

        $pastResult = $this->resolver->resolveWithTrace(
            $variant,
            $customer,
            2,
            CarbonImmutable::parse('2026-07-01'),
        );

        $expiredSteps = collect($pastResult->trace->steps)
            ->filter(fn ($step) => $step->reason === PriceResolutionReason::Expired);
        $this->assertGreaterThanOrEqual(1, $expiredSteps->count());
    }

    public function test_trace_does_not_contain_cost_or_margin(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $encoded = json_encode($result->trace);

        $this->assertStringNotContainsString('cost', strtolower($encoded));
        $this->assertStringNotContainsString('margin', strtolower($encoded));
    }

    public function test_result_restores_price_not_available_exception(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->expectException(PriceNotAvailableException::class);
        $this->expectExceptionMessage("No price available for variant {$variant->id} at quantity 1.");
        $result->toResolvedPrice();
    }

    public function test_result_restores_price_list_configuration_exception(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = Workspace::query()->create([
            'name' => 'Config Error Workspace',
            'is_default' => false,
        ]);
        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);

        $this->expectException(PriceListConfigurationException::class);
        $this->expectExceptionMessage("Workspace {$workspace->id} has no active default price list.");
        $result->toResolvedPrice();
    }

    /**
     * @param  PriceResolutionResult  $result
     */
    private function findStep($result, PriceResolutionSource $source): PriceResolutionStep
    {
        foreach ($result->trace->steps as $step) {
            if ($step->source === $source) {
                return $step;
            }
        }

        $this->fail('Step not found for source: '.$source->value);
    }
}
