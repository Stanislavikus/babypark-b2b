<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Support\Workspace\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_returns_the_single_default_workspace(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->sole();

        $context = app(WorkspaceContext::class);
        $context->reset();

        $this->assertTrue($context->default()->is($workspace));
        $this->assertSame($workspace->id, $context->id());
        $this->assertTrue($context->current()->is($workspace));
    }

    public function test_default_throws_when_no_default_workspace_exists(): void
    {
        Workspace::query()->update(['is_default' => false]);

        $context = app(WorkspaceContext::class);
        $context->reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No default workspace found');

        $context->default();
    }

    public function test_default_throws_when_multiple_default_workspaces_exist(): void
    {
        Schema::disableForeignKeyConstraints();
        \App\Models\PriceListItem::withoutWorkspaceScope()->delete();
        \App\Models\PriceList::withoutWorkspaceScope()->delete();
        \App\Models\Contractor::withoutWorkspaceScope()->delete();
        \App\Models\ProductVariant::withoutWorkspaceScope()->delete();
        \App\Models\Product::withoutWorkspaceScope()->delete();
        \App\Models\Category::withoutWorkspaceScope()->delete();
        Workspace::query()->delete();
        Schema::enableForeignKeyConstraints();

        Workspace::query()->create(['name' => 'One', 'is_default' => true]);
        Workspace::query()->create(['name' => 'Two', 'is_default' => true]);

        $context = app(WorkspaceContext::class);
        $context->reset();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Multiple default workspaces found');

        $context->default();
    }
}
