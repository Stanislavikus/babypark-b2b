<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\Pricing\WorkspaceTaxDefaults;
use LogicException;
use Tests\TestCase;

class WorkspaceTaxDefaultsTest extends TestCase
{
    public function test_resolve_workspace_rate_returns_float_from_decimal_cast_string(): void
    {
        $workspace = new Workspace([
            'name' => 'Cast Test',
            'default_vat_rate' => '20.00',
        ]);

        $rate = app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate($workspace);

        $this->assertIsFloat($rate);
        $this->assertSame(20.0, $rate);
    }

    public function test_resolve_workspace_rate_throws_when_default_vat_rate_missing(): void
    {
        $workspace = new Workspace([
            'name' => 'Missing Rate',
            'default_vat_rate' => null,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no default tax rate');

        app(WorkspaceTaxDefaults::class)->resolveWorkspaceRate($workspace);
    }

    public function test_resolve_item_rate_uses_item_rate_when_present(): void
    {
        $workspace = new Workspace([
            'name' => 'Workspace',
            'default_vat_rate' => '20.00',
        ]);

        $rate = app(WorkspaceTaxDefaults::class)->resolveItemRate('19', $workspace);

        $this->assertSame(19.0, $rate);
    }

    public function test_resolve_item_rate_falls_back_to_workspace_rate(): void
    {
        $workspace = new Workspace([
            'name' => 'Workspace',
            'default_vat_rate' => '19.00',
        ]);

        $rate = app(WorkspaceTaxDefaults::class)->resolveItemRate(null, $workspace);

        $this->assertSame(19.0, $rate);
    }
}
