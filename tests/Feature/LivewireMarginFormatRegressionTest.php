<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GAP-024 PR4: Livewire 4 must preserve Livewire::current() context for margin
 * presentation toggles in Filament product tables.
 */
class LivewireMarginFormatRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_products_margin_format_toggle_survives_livewire_4_render_cycle(): void
    {
        $admin = User::query()->create([
            'name' => 'Margin Format Admin',
            'email' => 'margin-format-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ListProducts::class);

        $component
            ->assertSet('marginFormat', 'percent')
            ->assertOk();

        // Table render exercises Livewire::current() inside margin column closures.
        $component->html();

        $component
            ->call('toggleMarginFormat')
            ->assertSet('marginFormat', 'uah')
            ->assertOk();

        $component->html();

        $component
            ->call('toggleMarginFormat')
            ->assertSet('marginFormat', 'percent')
            ->assertOk();
    }
}
