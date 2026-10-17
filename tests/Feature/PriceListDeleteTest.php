<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PriceListResource\Pages\EditPriceList;
use App\Filament\Resources\PriceListResource\Pages\ListPriceLists;
use App\Filament\Resources\PriceListResource\Support\PriceListGuard;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceListDeleteTest extends TestCase
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
            'name' => 'Price List Admin',
            'email' => 'pricelist-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_delete_is_blocked_when_price_list_has_assigned_customers(): void
    {
        $priceList = $this->createPriceList($this->workspace);
        $customer = $this->createCustomer($this->workspace);
        $customer->update(['default_price_list_id' => $priceList->id]);

        $reason = PriceListGuard::deleteBlockReason($priceList->fresh());

        $this->assertFalse($reason['allowed']);
        $this->assertSame('Неможливо видалити прайс-лист', $reason['title']);
        $this->assertStringContainsString($customer->name, (string) $reason['body']);

        Livewire::actingAs($this->admin)
            ->test(ListPriceLists::class)
            ->callTableAction('delete', $priceList)
            ->assertNotified();

        $this->assertDatabaseHas('price_lists', ['id' => $priceList->id]);
    }

    public function test_delete_succeeds_when_price_list_has_no_assigned_customers(): void
    {
        $priceList = $this->createPriceList($this->workspace);

        $reason = PriceListGuard::deleteBlockReason($priceList);

        $this->assertTrue($reason['allowed']);

        Livewire::actingAs($this->admin)
            ->test(EditPriceList::class, ['record' => $priceList->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('price_lists', ['id' => $priceList->id]);
    }
}
