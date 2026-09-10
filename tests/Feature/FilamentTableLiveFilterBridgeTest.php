<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GAP-024 PR3: Filament 4 defaults tables to deferred filters; the project
 * restores Filament 3 live-filter UX via Table::configureUsing(deferFilters(false)).
 */
class FilamentTableLiveFilterBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_table_configure_using_keeps_filters_live_without_apply_action(): void
    {
        $admin = User::query()->create([
            'name' => 'Live Filter Admin',
            'email' => 'live-filter-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $workspace = Workspace::query()->where('is_default', true)->sole();

        $active = Product::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'LIVE-FILTER-ACTIVE',
            'name' => 'Live Filter Active Product',
            'is_active' => true,
        ]);

        $inactive = Product::query()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => Str::uuid()->toString(),
            'sku' => 'LIVE-FILTER-INACTIVE',
            'name' => 'Live Filter Inactive Product',
            'is_active' => false,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ListProducts::class);

        $this->assertFalse(
            $component->instance()->getTable()->hasDeferredFilters(),
            'Global Table::configureUsing must keep Filament tables non-deferred (Filament 3 live UX).',
        );

        // ProductResource defaults the status filter to "active".
        $component
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);

        // Changing the filter must update the query immediately — no applyTableFilters call.
        $component
            ->filterTable('status', 'inactive')
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);

        $this->assertFalse(
            $component->instance()->getTable()->getFiltersApplyAction()->isVisible(),
            'Deferred "Apply filters" action must remain hidden when filters are live.',
        );
    }
}
