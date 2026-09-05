<?php

namespace Tests\Feature;

use App\Enums\PriceListStatus;
use App\Enums\UserRole;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\CustomerPriceListAssignmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CustomerPriceListAssignmentTest extends TestCase
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
            'name' => 'Assignment Admin',
            'email' => 'assignment-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_single_assignment_persists_active_price_list(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $optList = $this->createPriceList($this->workspace, isDefault: false);

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['default_price_list_id' => $optList->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($optList->id, $customer->fresh()->default_price_list_id);
    }

    public function test_single_assignment_rejects_invalid_targets_when_changing(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('is_default', true)
            ->firstOrFail();
        $inactive = $this->createPriceList($this->workspace, status: PriceListStatus::Inactive);

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace',
            'is_default' => false,
        ]);
        $foreignList = PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign List',
            'currency' => 'UAH',
            'is_default' => true,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->set('data.default_price_list_id', $defaultList->id)
            ->call('save')
            ->assertHasFormErrors(['default_price_list_id']);

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->set('data.default_price_list_id', $inactive->id)
            ->call('save')
            ->assertHasFormErrors(['default_price_list_id']);

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->set('data.default_price_list_id', $foreignList->id)
            ->call('save')
            ->assertHasFormErrors(['default_price_list_id']);
    }

    public function test_unrelated_save_with_unchanged_inactive_or_redundant_assignment_succeeds(): void
    {
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('is_default', true)
            ->firstOrFail();
        $inactive = $this->createPriceList($this->workspace, status: PriceListStatus::Inactive);

        $inactiveCustomer = $this->createCustomer($this->workspace);
        $inactiveCustomer->update(['default_price_list_id' => $inactive->id]);

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $inactiveCustomer->getRouteKey()])
            ->fillForm([
                'email' => 'inactive-historical@example.com',
                'default_price_list_id' => $inactive->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($inactive->id, $inactiveCustomer->fresh()->default_price_list_id);
        $this->assertSame('inactive-historical@example.com', $inactiveCustomer->fresh()->email);

        $redundantCustomer = $this->createCustomer($this->workspace);
        $redundantCustomer->update(['default_price_list_id' => $defaultList->id]);

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $redundantCustomer->getRouteKey()])
            ->fillForm([
                'email' => 'redundant@example.com',
                'default_price_list_id' => $defaultList->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($defaultList->id, $redundantCustomer->fresh()->default_price_list_id);
        $this->assertSame('redundant@example.com', $redundantCustomer->fresh()->email);
    }

    public function test_null_assignment_displays_inherited_default_across_surfaces(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $customer->update(['default_price_list_id' => null, 'name' => 'Inherited Default Customer']);

        Livewire::actingAs($this->admin)
            ->test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertSee('Основний прайс компанії')
            ->assertSee('Індивідуальний прайс-лист не призначено');

        Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->assertSee('Inherited Default Customer')
            ->assertSee('Основний прайс компанії');
    }

    public function test_inactive_and_redundant_states_display_consistently(): void
    {
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('is_default', true)
            ->firstOrFail();

        $inactive = PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Опт-2',
            'currency' => 'UAH',
            'is_default' => false,
            'priority' => 0,
            'status' => PriceListStatus::Inactive,
        ]);

        $inactiveCustomer = $this->createCustomer($this->workspace);
        $inactiveCustomer->update([
            'default_price_list_id' => $inactive->id,
            'name' => 'Inactive Assignment Customer',
        ]);

        $redundantCustomer = $this->createCustomer($this->workspace);
        $redundantCustomer->update([
            'default_price_list_id' => $defaultList->id,
            'name' => 'Redundant Assignment Customer',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewCustomer::class, ['record' => $inactiveCustomer->getRouteKey()])
            ->assertSee('Опт-2')
            ->assertSee('неактивний')
            ->assertSee('Фактично використовується основний прайс-лист компанії');

        Livewire::actingAs($this->admin)
            ->test(ViewCustomer::class, ['record' => $redundantCustomer->getRouteKey()])
            ->assertSee('основний прайс-лист компанії')
            ->assertSee('Рекомендується використовувати «За замовчуванням»');

        Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->assertSee('Inactive Assignment Customer')
            ->assertSee('Опт-2')
            ->assertSee('Неактивний')
            ->assertSee('Redundant Assignment Customer')
            ->assertSee('рекомендується «За замовчуванням»');

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $inactiveCustomer->getRouteKey()])
            ->assertSee('неактивний')
            ->assertSee('Фактично використовується основний прайс-лист компанії');

        Livewire::actingAs($this->admin)
            ->test(EditCustomer::class, ['record' => $redundantCustomer->getRouteKey()])
            ->assertSee('основний прайс-лист компанії')
            ->assertSee('Рекомендується обрати «За замовчуванням»');
    }

    public function test_bulk_assignment_and_clear_to_default_via_sentinel(): void
    {
        $target = PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Bulk Target',
            'currency' => 'UAH',
            'is_default' => false,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);
        $other = $this->createPriceList($this->workspace, isDefault: false);

        $first = $this->createCustomer($this->workspace);
        $second = $this->createCustomer($this->workspace);
        $second->update(['default_price_list_id' => $other->id]);

        $component = Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->mountTableBulkAction('assign_price_list', [$first, $second])
            ->setTableBulkActionData([
                'target_price_list_id' => $target->id,
            ])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertSame($target->id, $first->fresh()->default_price_list_id);
        $this->assertSame($target->id, $second->fresh()->default_price_list_id);

        Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->mountTableBulkAction('assign_price_list', [$first, $second])
            ->setTableBulkActionData([
                'target_price_list_id' => CustomerPriceListAssignmentService::WORKSPACE_DEFAULT_SENTINEL,
            ])
            ->callMountedTableBulkAction()
            ->assertNotified();

        $this->assertNull($first->fresh()->default_price_list_id);
        $this->assertNull($second->fresh()->default_price_list_id);
    }

    public function test_bulk_select_all_matching_filter_updates_only_matching_customers(): void
    {
        $target = PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Filter Bulk Target',
            'currency' => 'UAH',
            'is_default' => false,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);

        $activeCustomers = collect();
        for ($i = 0; $i < 12; $i++) {
            $customer = $this->createCustomer($this->workspace);
            $customer->update([
                'name' => "Active Filter Customer {$i}",
                'is_active' => true,
            ]);
            $activeCustomers->push($customer);
        }

        $inactiveOnPage = $this->createCustomer($this->workspace);
        $inactiveOnPage->update([
            'name' => 'Inactive On Page Customer',
            'is_active' => false,
        ]);

        $inactiveOffPage = $this->createCustomer($this->workspace);
        $inactiveOffPage->update([
            'name' => 'Inactive Off Page Customer',
            'is_active' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(ListCustomers::class)
            ->set('tableFilters', [
                'is_active' => [
                    'value' => true,
                ],
            ]);

        $keys = $component->instance()->getAllSelectableTableRecordKeys();
        $component->set('selectedTableRecords', $keys);

        $this->assertCount(12, $keys);
        $this->assertNotContains((string) $inactiveOnPage->id, $keys);
        $this->assertNotContains((string) $inactiveOffPage->id, $keys);

        $component
            ->mountTableBulkAction('assign_price_list', $activeCustomers->all())
            ->setTableBulkActionData([
                'target_price_list_id' => $target->id,
            ])
            ->callMountedTableBulkAction()
            ->assertNotified();

        foreach ($activeCustomers as $customer) {
            $this->assertSame($target->id, $customer->fresh()->default_price_list_id);
        }

        $this->assertNull($inactiveOnPage->fresh()->default_price_list_id);
        $this->assertNull($inactiveOffPage->fresh()->default_price_list_id);
    }
}
