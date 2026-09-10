<?php

namespace Tests\Unit;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceCreatingHookTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_create_uses_config_default_vat_rate_and_is_immutable_after_config_change(): void
    {
        config(['pricing.default_vat_rate' => 20]);

        $workspace = Workspace::query()->create([
            'name' => 'Hook Workspace',
            'is_default' => false,
        ]);

        $this->assertSame('20.00', (string) $workspace->default_vat_rate);

        config(['pricing.default_vat_rate' => 19]);
        $workspace->refresh();

        $this->assertSame('20.00', (string) $workspace->default_vat_rate);
    }
}
