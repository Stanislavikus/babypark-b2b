<?php

namespace Tests\Unit;

use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Models\PriceList;
use App\Services\Pricing\PriceResolutionSnapshot;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\Resolution\PriceResolutionReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceResolutionSnapshotTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_cache_hit_restores_identical_unavailable_exception(): void
    {
        $customer = $this->createCustomer();
        $variant = $this->createVariant();
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::parse('2026-07-13 10:00:00'));
        $resolver = app(PriceResolver::class);

        PriceResolver::resetStandardResolutionExecutions();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $resolver->resolveForCustomer($variant, $customer, 1, snapshot: $snapshot);
            $this->fail('Expected PriceNotAvailableException');
        } catch (PriceNotAvailableException $fresh) {
            $freshQueries = count(DB::getQueryLog());
        }

        DB::flushQueryLog();
        PriceResolver::resetStandardResolutionExecutions();

        try {
            $resolver->resolveForCustomer($variant, $customer, 1, snapshot: $snapshot);
            $this->fail('Expected PriceNotAvailableException on cache hit');
        } catch (PriceNotAvailableException $cached) {
            $this->assertSame(0, count(DB::getQueryLog()), 'Cache hit must not execute SQL');
            $this->assertSame(0, PriceResolver::standardResolutionExecutions());
            $this->assertSame(PriceNotAvailableException::class, $cached::class);
            $this->assertSame($fresh->getMessage(), $cached->getMessage());
            $this->assertSame($fresh->context, $cached->context);
        }

        $this->assertGreaterThan(0, $freshQueries);
    }

    public function test_cache_hit_restores_identical_configuration_exception(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL enforces single default price list via database constraint.');
        }

        $workspace = $this->defaultWorkspace();
        $variant = $this->createVariant($workspace);

        PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $this->createPriceList($workspace, isDefault: true);
        $this->createPriceList($workspace, isDefault: true);

        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::parse('2026-07-13 10:00:00'));
        $resolver = app(PriceResolver::class);

        try {
            $resolver->resolveDefault($variant, 1, snapshot: $snapshot);
            $this->fail('Expected configuration exception');
        } catch (PriceListConfigurationException $fresh) {
            $this->assertSame(PriceResolutionReason::DefaultPriceListMisconfigured, $fresh->reason);
        }

        DB::flushQueryLog();
        PriceResolver::resetStandardResolutionExecutions();

        try {
            $resolver->resolveDefault($variant, 1, snapshot: $snapshot);
            $this->fail('Expected configuration exception on cache hit');
        } catch (PriceListConfigurationException $cached) {
            $this->assertSame(0, count(DB::getQueryLog()));
            $this->assertSame($fresh->getMessage(), $cached->getMessage());
            $this->assertSame($fresh->reason, $cached->reason);
            $this->assertSame($fresh->context, $cached->context);
        }
    }

    public function test_rejects_both_effective_at_and_snapshot(): void
    {
        $variant = $this->createVariant();
        $customer = $this->createCustomer();
        $snapshot = new PriceResolutionSnapshot(CarbonImmutable::now());

        $this->expectException(\InvalidArgumentException::class);
        app(PriceResolver::class)->resolveForCustomer(
            $variant,
            $customer,
            1,
            effectiveAt: CarbonImmutable::now(),
            snapshot: $snapshot,
        );
    }
}
