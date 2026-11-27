<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\PreviewAsCustomer;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PreviewAsCustomerTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private User $admin;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->defaultWorkspace();

        $this->admin = User::query()->create([
            'name' => 'Preview Admin',
            'email' => 'preview-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_preview_as_customer_for_customer_in_current_workspace(): void
    {
        $customer = $this->createCustomer($this->workspace);

        $this->actingAs($this->admin)
            ->get("/admin/customers/{$customer->id}/preview")
            ->assertOk();

        Livewire::actingAs($this->admin)
            ->test(PreviewAsCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertSee($customer->name);
    }

    public function test_preview_url_from_customer_resource_resolves_for_same_customer(): void
    {
        $customer = $this->createCustomer($this->workspace);

        $url = CustomerResource::getUrl('preview', ['record' => $customer]);

        $this->actingAs($this->admin)
            ->get($url)
            ->assertOk();
    }

    public function test_preview_returns_not_found_for_customer_in_foreign_workspace(): void
    {
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace',
            'is_default' => false,
        ]);
        $foreignCustomer = $this->createCustomer($foreignWorkspace);

        $this->actingAs($this->admin)
            ->get("/admin/customers/{$foreignCustomer->id}/preview")
            ->assertNotFound();
    }
}
