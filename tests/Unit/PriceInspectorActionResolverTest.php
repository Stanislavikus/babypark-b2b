<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\Inspection\PriceInspectorActionResolver;
use App\Services\Pricing\Inspection\PriceInspectorContext;
use App\Services\Pricing\Inspection\PriceInspectorPresenter;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use App\Services\Pricing\Resolution\PriceResolutionSource;
use App\Services\Pricing\Resolution\PriceResolutionStep;
use App\Services\Pricing\Resolution\PriceResolutionStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceInspectorActionResolverTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private PriceInspectorActionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(PriceInspectorActionResolver::class);
    }

    public function test_matched_customer_price_list_returns_open_price_list_action(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $result = app(PriceResolver::class)->resolveWithTrace($variant, $customer, 1);
        $step = collect($result->trace->steps)
            ->first(fn ($s) => $s->source === PriceResolutionSource::CustomerPriceList
                && $s->reason === PriceResolutionReason::Matched);

        $action = $this->resolver->forStep($step, $this->context($customer, $variant));

        $this->assertNotNull($action);
        $this->assertSame(__('price_inspector.action.open_price_list'), $action->label);
        $this->assertStringContainsString((string) $list->id, $action->url);
        $this->assertStringNotContainsString('tableSearch', $action->url);
        $this->assertSame('price_list:'.$list->id, $action->deduplicationKey);
    }

    public function test_matched_workspace_default_price_list_returns_open_price_list_action(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $variant->workspace_id)
            ->where('is_default', true)
            ->firstOrFail();
        $this->createPriceListItem($defaultList, $variant, 55.50);

        $result = app(PriceResolver::class)->resolveWithTrace($variant, $customer, 1);
        $step = collect($result->trace->steps)
            ->first(fn ($s) => $s->source === PriceResolutionSource::WorkspaceDefaultPriceList
                && $s->reason === PriceResolutionReason::Matched);

        $action = $this->resolver->forStep($step, $this->context($customer, $variant));

        $this->assertNotNull($action);
        $this->assertSame(__('price_inspector.action.open_price_list'), $action->label);
        $this->assertStringContainsString((string) $defaultList->id, $action->url);
    }

    public function test_matched_base_price_cache_returns_open_product_action(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant(basePriceCache: 42.00);

        $result = app(PriceResolver::class)->resolveWithTrace($variant, $customer, 1);
        $step = collect($result->trace->steps)
            ->first(fn ($s) => $s->source === PriceResolutionSource::BasePriceCache
                && $s->reason === PriceResolutionReason::Matched);

        $action = $this->resolver->forStep($step, $this->context($customer, $variant));

        $this->assertNotNull($action);
        $this->assertSame(__('price_inspector.action.open_product'), $action->label);
        $this->assertStringContainsString((string) $variant->product_id, $action->url);
        $this->assertSame('product:'.$variant->product_id, $action->deduplicationKey);
    }

    public function test_matched_with_missing_price_list_id_returns_null(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $step = new PriceResolutionStep(
            source: PriceResolutionSource::CustomerPriceList,
            status: PriceResolutionStepStatus::Matched,
            reason: PriceResolutionReason::Matched,
            priceListId: null,
        );

        $action = $this->resolver->forStep($step, $this->context($customer, $variant));

        $this->assertNull($action);
    }

    public function test_matched_with_foreign_price_list_id_returns_null(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Matched Workspace',
            'is_default' => false,
        ]);
        $foreignList = $this->createPriceList($otherWorkspace);
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $step = new PriceResolutionStep(
            source: PriceResolutionSource::CustomerPriceList,
            status: PriceResolutionStepStatus::Matched,
            reason: PriceResolutionReason::Matched,
            priceListId: $foreignList->id,
        );

        $action = $this->resolver->forStep($step, $this->context($customer, $variant));

        $this->assertNull($action);
    }

    public function test_resolved_on_first_source_shows_one_decision_path_step(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $presentation = $this->presentResolved($customer, $variant);

        $this->assertCount(1, $presentation->sourceSteps);
        $this->assertSame(__('price_inspector.source.customer_price_list'), $presentation->sourceSteps[0]->sourceLabel);
        $this->assertNotNull($presentation->sourceSteps[0]->action);
    }

    public function test_resolved_on_second_source_shows_two_decision_path_steps(): void
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

        $presentation = $this->presentResolved($customer, $variant);

        $this->assertCount(2, $presentation->sourceSteps);
        $this->assertSame(__('price_inspector.source.customer_price_list'), $presentation->sourceSteps[0]->sourceLabel);
        $this->assertSame(__('price_inspector.source.workspace_default_price_list'), $presentation->sourceSteps[1]->sourceLabel);
    }

    public function test_resolved_on_third_source_shows_three_decision_path_steps(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant(basePriceCache: 42.00);

        $presentation = $this->presentResolved($customer, $variant);

        $this->assertCount(3, $presentation->sourceSteps);
        $this->assertSame(__('price_inspector.source.customer_price_list'), $presentation->sourceSteps[0]->sourceLabel);
        $this->assertSame(__('price_inspector.source.workspace_default_price_list'), $presentation->sourceSteps[1]->sourceLabel);
        $this->assertSame(__('price_inspector.source.base_price_cache'), $presentation->sourceSteps[2]->sourceLabel);
        $this->assertSame(__('price_inspector.action.open_product'), $presentation->sourceSteps[2]->action?->label);
    }

    private function presentResolved($customer, $variant)
    {
        $result = app(PriceResolver::class)->resolveWithTrace($variant, $customer, 1);

        return app(PriceInspectorPresenter::class)->present(
            $result,
            $this->context($customer, $variant),
        );
    }

    private function context($customer, $variant): PriceInspectorContext
    {
        $admin = User::query()->create([
            'name' => 'Resolver Admin',
            'email' => 'resolver-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        return new PriceInspectorContext(
            customer: $customer,
            variant: $variant,
            quantity: 1,
            effectiveAt: CarbonImmutable::now(),
            user: $admin,
        );
    }
}
