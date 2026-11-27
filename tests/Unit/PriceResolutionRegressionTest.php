<?php

namespace Tests\Unit;

use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\ProductPricingSummary;
use App\Services\Pricing\Resolution\PriceResolutionFailure;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\Resolution\PriceResolutionResult;
use App\Services\Pricing\Resolution\PriceResolutionTrace;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceResolutionRegressionTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_variant_price_display_restores_same_unavailable_exception(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $failure = new PriceResolutionFailure(
            reason: PriceResolutionReason::AllSourcesExhausted,
            message: "No price available for variant {$variant->id} at quantity 1.",
            context: [
                'variant_id' => $variant->id,
                'quantity' => 1,
                'workspace_id' => $variant->workspace_id,
            ],
        );

        $result = PriceResolutionResult::unavailable(
            reasonCodes: [PriceResolutionReason::AllSourcesExhausted],
            trace: new PriceResolutionTrace([]),
            failure: $failure,
        );

        try {
            $result->toResolvedPrice();
            $this->fail('Expected PriceNotAvailableException');
        } catch (PriceNotAvailableException $direct) {
            $display = app(ProductPricingSummary::class);
            $resolvedDisplay = $display->resolveVariantDisplay($variant, $customer, 1);

            $this->assertFalse($resolvedDisplay->available);

            try {
                $result->toResolvedPrice();
            } catch (PriceNotAvailableException $restored) {
                $this->assertSame(PriceNotAvailableException::class, $restored::class);
                $this->assertSame($failure->message, $restored->getMessage());
                $this->assertSame($failure->message, $direct->getMessage());
                $this->assertSame($failure->context['variant_id'], $variant->id);
            }
        }
    }

    public function test_session_cart_handles_unavailable_price_same_as_before(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        SessionCart::add($variant->id, 1);
        $lines = SessionCart::resolvedLinesForCustomer($customer);

        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['price_available']);

        $failure = new PriceResolutionFailure(
            reason: PriceResolutionReason::AllSourcesExhausted,
            message: "No price available for variant {$variant->id} at quantity 1.",
            context: [
                'variant_id' => $variant->id,
                'quantity' => 1,
                'workspace_id' => $variant->workspace_id,
            ],
        );

        $result = PriceResolutionResult::unavailable(
            reasonCodes: [PriceResolutionReason::AllSourcesExhausted],
            trace: new PriceResolutionTrace([]),
            failure: $failure,
        );

        try {
            $result->toResolvedPrice();
            $this->fail('Expected exception');
        } catch (PriceNotAvailableException $exception) {
            $this->assertSame(PriceNotAvailableException::class, $exception::class);
            $this->assertSame($failure->message, $exception->getMessage());
            $this->assertSame($variant->id, $failure->context['variant_id']);
        }

        SessionCart::clear();
    }

    public function test_order_creator_path_restores_configuration_exception(): void
    {
        $failure = new PriceResolutionFailure(
            reason: PriceResolutionReason::DefaultPriceListMisconfigured,
            message: 'Workspace test-workspace has no active default price list.',
            context: ['workspace_id' => 'test-workspace'],
        );

        $result = PriceResolutionResult::configurationError(
            reasonCodes: [PriceResolutionReason::DefaultPriceListMisconfigured],
            trace: new PriceResolutionTrace([]),
            failure: $failure,
        );

        try {
            $result->toResolvedPrice();
            $this->fail('Expected PriceListConfigurationException');
        } catch (PriceListConfigurationException $exception) {
            $this->assertSame(PriceListConfigurationException::class, $exception::class);
            $this->assertSame($failure->message, $exception->getMessage());
            $this->assertSame('test-workspace', $failure->context['workspace_id']);
        }
    }

    public function test_resolved_result_returns_same_resolved_price(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $direct = app(PriceResolver::class)->resolveForCustomer($variant, $customer, 1);
        $traced = app(PriceResolver::class)->resolveWithTrace($variant, $customer, 1)->toResolvedPrice();

        $this->assertSame($direct->effectiveNetPrice, $traced->effectiveNetPrice);
        $this->assertSame($direct->grossPrice, $traced->grossPrice);
        $this->assertSame($direct->source, $traced->source);
        $this->assertSame($direct->sourcePriceListId, $traced->sourcePriceListId);
    }
}
