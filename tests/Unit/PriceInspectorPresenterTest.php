<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\Inspection\PriceInspectorContext;
use App\Services\Pricing\Inspection\PriceInspectorPresentation;
use App\Services\Pricing\Inspection\PriceInspectorPresenter;
use App\Services\Pricing\Inspection\PriceInspectorTone;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceInspectorPresenterTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private PriceInspectorPresenter $presenter;

    private PriceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = app(PriceInspectorPresenter::class);
        $this->resolver = app(PriceResolver::class);
    }

    public function test_resolved_presentation_has_success_headline_and_tone(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 1249.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $this->assertSame(PriceResolutionStatus::Resolved, $result->status);
        $this->assertSame(__('price_inspector.headline.resolved'), $presentation->headline);
        $this->assertSame(PriceInspectorTone::Success, $presentation->tone);
        $this->assertSame(__('price_inspector.outcome.resolved'), $presentation->summary);
        $this->assertNotNull($presentation->priceSummary);
        $this->assertStringContainsString('₴', $presentation->priceSummary);
    }

    public function test_unavailable_presentation_has_warning_headline_and_tone(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $this->assertSame(PriceResolutionStatus::Unavailable, $result->status);
        $this->assertSame(__('price_inspector.headline.unavailable'), $presentation->headline);
        $this->assertSame(PriceInspectorTone::Warning, $presentation->tone);
        $this->assertSame(__('price_inspector.outcome.unavailable'), $presentation->summary);
        $this->assertNull($presentation->priceSummary);
        $this->assertNotEmpty($presentation->recommendedActions);
    }

    public function test_configuration_error_presentation_has_critical_headline_and_tone(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = Workspace::query()->create([
            'name' => 'Config Workspace Presenter',
            'is_default' => false,
        ]);
        $customer = $this->createCustomer($workspace);
        $variant = $this->createVariant($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $this->assertSame(PriceResolutionStatus::ConfigurationError, $result->status);
        $this->assertSame(__('price_inspector.headline.configuration_error'), $presentation->headline);
        $this->assertSame(PriceInspectorTone::Critical, $presentation->tone);
        $this->assertSame(__('price_inspector.outcome.configuration_error'), $presentation->summary);
        $this->assertNotEmpty($presentation->recommendedActions);
        $this->assertSame(
            __('price_inspector.action.open_price_list_settings'),
            $presentation->recommendedActions[0]->label,
        );
    }

    public function test_source_steps_show_real_price_list_names_not_uuids(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $list->update(['name' => 'Оптовий Україна']);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $matchedStep = collect($presentation->sourceSteps)
            ->first(fn ($step) => $step->sourceName === 'Оптовий Україна');

        $this->assertNotNull($matchedStep);
        $this->assertStringNotContainsString($list->id, $matchedStep->explanation);
    }

    public function test_expired_explanation_contains_formatted_date(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem(
            $list,
            $variant,
            90.00,
            validUntil: CarbonImmutable::parse('2026-06-01'),
        );

        $result = $this->resolver->resolveWithTrace(
            $variant,
            $customer,
            1,
            CarbonImmutable::parse('2026-07-01'),
        );

        $presentation = $this->present($result, $customer, $variant, 1, CarbonImmutable::parse('2026-07-01'));

        $expiredStep = collect($presentation->sourceSteps)
            ->first(fn ($step) => str_contains($step->explanation, 'діяла до'));

        $this->assertNotNull($expiredStep);
        $this->assertStringContainsString('2026', $expiredStep->explanation);
        $this->assertNotEmpty($presentation->recommendedActions);
        $this->assertSame(
            __('price_inspector.action.extend_validity'),
            $presentation->recommendedActions[0]->label,
        );
    }

    public function test_quantity_below_minimum_recommends_check_quantity_action(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 100.00, quantityMin: 10);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 5);
        $presentation = $this->present($result, $customer, $variant, 5);

        $qtyStep = collect($presentation->sourceSteps)
            ->first(fn ($step) => str_contains($step->explanation, '10'));

        $this->assertNotNull($qtyStep);
        $this->assertStringContainsString('10', $qtyStep->explanation);

        $checkAction = collect($presentation->recommendedActions)
            ->first(fn ($action) => str_contains($action->label, '10'));

        $this->assertNotNull($checkAction);
        $this->assertStringContainsString('quantity=10', $checkAction->url);
    }

    public function test_price_list_not_assigned_recommends_assign_action(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $step = collect($presentation->sourceSteps)
            ->first(fn ($step) => $step->sourceLabel === __('price_inspector.source.customer_price_list')
                && str_contains($step->explanation, 'не призначено'));

        $this->assertNotNull($step);

        $assignAction = collect($presentation->recommendedActions)
            ->first(fn ($action) => $action->label === __('price_inspector.action.assign_price_list'));

        $this->assertNotNull($assignAction);
        $this->assertStringContainsString((string) $customer->id, $assignAction->url);
    }

    public function test_base_price_cache_item_missing_recommends_set_base_price(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $baseStep = collect($presentation->sourceSteps)
            ->first(fn ($step) => $step->sourceLabel === __('price_inspector.source.base_price_cache'));

        $this->assertNotNull($baseStep);
        $this->assertSame('Базову ціну не задано.', $baseStep->explanation);

        $setBaseAction = collect($presentation->recommendedActions)
            ->first(fn ($action) => $action->label === __('price_inspector.action.set_base_price'));

        $this->assertNotNull($setBaseAction);
        $this->assertStringContainsString((string) $variant->product_id, $setBaseAction->url);
    }

    public function test_workspace_default_item_missing_has_distinct_explanation(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant(basePriceCache: 42.00);

        $result = $this->resolver->resolveWithTrace($variant, $customer, 1);
        $presentation = $this->present($result, $customer, $variant, 1);

        $defaultStep = collect($presentation->sourceSteps)
            ->first(fn ($step) => $step->sourceLabel === __('price_inspector.source.workspace_default_price_list')
                && str_contains($step->explanation, 'основному'));

        $this->assertNotNull($defaultStep);
        $this->assertStringContainsString($variant->sku, $defaultStep->explanation);
    }

    public function test_recommended_actions_are_deduplicated(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem(
            $list,
            $variant,
            100.00,
            quantityMin: 1,
            validUntil: CarbonImmutable::parse('2026-01-01'),
        );
        $this->createPriceListItem(
            $list,
            $variant,
            90.00,
            quantityMin: 2,
            validUntil: CarbonImmutable::parse('2026-01-01'),
        );

        $result = $this->resolver->resolveWithTrace(
            $variant,
            $customer,
            5,
            CarbonImmutable::parse('2026-07-01'),
        );
        $presentation = $this->present($result, $customer, $variant, 5, CarbonImmutable::parse('2026-07-01'));

        $dedupKeys = array_map(
            fn ($action) => $action->deduplicationKey,
            $presentation->recommendedActions,
        );

        $this->assertSame(count($dedupKeys), count(array_unique($dedupKeys)));
    }

    public function test_action_not_shown_when_price_list_belongs_to_foreign_workspace(): void
    {
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Action Workspace',
            'is_default' => false,
        ]);
        $foreignList = $this->createPriceList($otherWorkspace);

        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $list = $this->createPriceList();
        $customer->update(['default_price_list_id' => $list->id]);

        $this->createPriceListItem(
            $list,
            $variant,
            90.00,
            validUntil: CarbonImmutable::parse('2026-01-01'),
        );

        $result = $this->resolver->resolveWithTrace(
            $variant,
            $customer,
            1,
            CarbonImmutable::parse('2026-07-01'),
        );

        $presentation = $this->present($result, $customer, $variant, 1, CarbonImmutable::parse('2026-07-01'));

        foreach ($presentation->sourceSteps as $step) {
            if ($step->action !== null) {
                $this->assertStringNotContainsString($foreignList->id, $step->action->url);
            }
        }
    }

    public function test_each_reason_has_human_explanation_in_source_steps(): void
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
            quantityMin: 2,
            validFrom: CarbonImmutable::parse('2026-12-01'),
        );
        $this->createPriceListItem(
            $list,
            $variant,
            80.00,
            quantityMin: 3,
            validUntil: CarbonImmutable::parse('2026-01-01'),
        );

        $result = $this->resolver->resolveWithTrace(
            $variant,
            $customer,
            5,
            CarbonImmutable::parse('2026-07-01'),
        );
        $presentation = $this->present($result, $customer, $variant, 5, CarbonImmutable::parse('2026-07-01'));

        foreach ($presentation->sourceSteps as $step) {
            $this->assertNotEmpty($step->explanation);
            $this->assertStringNotContainsString('item_missing', $step->explanation);
            $this->assertStringNotContainsString('quantity_below_minimum', $step->explanation);
        }
    }

    /**
     * @return PriceInspectorPresentation
     */
    private function present(
        $result,
        $customer,
        $variant,
        int $quantity,
        ?CarbonImmutable $effectiveAt = null,
    ) {
        $admin = User::query()->create([
            'name' => 'Presenter Admin',
            'email' => 'presenter-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $context = new PriceInspectorContext(
            customer: $customer,
            variant: $variant,
            quantity: $quantity,
            effectiveAt: $effectiveAt ?? CarbonImmutable::now(),
            user: $admin,
        );

        return $this->presenter->present($result, $context);
    }
}
