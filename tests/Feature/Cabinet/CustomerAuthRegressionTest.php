<?php

namespace Tests\Feature\Cabinet;

use App\Livewire\Cabinet\Dashboard;
use App\Livewire\Cabinet\Login;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerAuthRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $workspace = Workspace::query()->where('is_default', true)->sole();

        $this->customer = Customer::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Auth Regression Customer',
            'short_name' => 'Auth Customer',
            'login' => 'auth-regression',
            'password' => 'secret',
            'is_active' => true,
        ]);
    }

    public function test_customer_guard_authenticates_and_redirects_to_catalog(): void
    {
        Livewire::test(Login::class)
            ->set('login', 'auth-regression')
            ->set('password', 'secret')
            ->call('authenticate')
            ->assertRedirect(route('cabinet.catalog'));

        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertSame($this->customer->id, Auth::guard('customer')->id());
    }

    public function test_guest_customer_middleware_redirects_authenticated_users_away_from_login(): void
    {
        $this->actingAs($this->customer, 'customer')
            ->get(route('cabinet.login'))
            ->assertRedirect(route('cabinet.dashboard'));
    }

    public function test_customer_authenticated_middleware_protects_dashboard(): void
    {
        $this->get(route('cabinet.dashboard'))
            ->assertRedirect(route('cabinet.login'));

        $this->actingAs($this->customer, 'customer')
            ->get(route('cabinet.dashboard'))
            ->assertOk();
    }

    public function test_logout_clears_customer_guard_and_redirects_to_login(): void
    {
        $this->actingAs($this->customer, 'customer');

        $this->post(route('cabinet.logout'))
            ->assertRedirect(route('cabinet.login'));

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_inactive_customer_cannot_authenticate(): void
    {
        $this->customer->update(['is_active' => false]);

        Livewire::test(Login::class)
            ->set('login', 'auth-regression')
            ->set('password', 'secret')
            ->call('authenticate')
            ->assertHasErrors(['login']);

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_dashboard_livewire_works_for_authenticated_customer(): void
    {
        Livewire::actingAs($this->customer, 'customer')
            ->test(Dashboard::class)
            ->assertOk();
    }
}
