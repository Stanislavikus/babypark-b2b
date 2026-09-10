<?php

namespace Tests\Unit;

use App\Enums\PriceDisplayContext;
use App\Enums\PriceDisplayMode;
use App\Models\Workspace;
use App\Services\Pricing\PriceDisplayModeResolver;
use Tests\TestCase;

class PriceDisplayModeResolverTest extends TestCase
{
    public function test_preview_and_cabinet_customer_facing_contexts_return_same_mode(): void
    {
        $workspace = new Workspace([
            'name' => 'Display Mode Workspace',
            'default_vat_rate' => '20.00',
            'default_price_display_mode' => PriceDisplayMode::TaxExclusivePrimary,
        ]);

        $resolver = app(PriceDisplayModeResolver::class);

        $previewMode = $resolver->resolve($workspace, PriceDisplayContext::CustomerFacing);
        $cabinetMode = $resolver->resolve($workspace, PriceDisplayContext::CustomerFacing);

        $this->assertSame(PriceDisplayMode::TaxExclusivePrimary, $previewMode);
        $this->assertSame($previewMode, $cabinetMode);
    }

    public function test_internal_context_uses_workspace_default_mode(): void
    {
        $workspace = new Workspace([
            'name' => 'Internal Mode Workspace',
            'default_vat_rate' => '20.00',
            'default_price_display_mode' => PriceDisplayMode::BothEqual,
        ]);

        $mode = app(PriceDisplayModeResolver::class)->resolve($workspace, PriceDisplayContext::Internal);

        $this->assertSame(PriceDisplayMode::BothEqual, $mode);
    }
}
