<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceListDeepLinkVerificationTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    public function test_relation_manager_table_search_query_string_identifier(): void
    {
        $workspace = $this->defaultWorkspace();
        $variant = $this->createVariant($workspace);
        $variant->update(['sku' => 'DEEP-LINK-SKU']);
        $list = $this->createPriceList($workspace);
        $this->createPriceListItem($list, $variant, 100.00);

        $admin = User::query()->create([
            'name' => 'Deep Link Admin',
            'email' => 'deeplink-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $list,
                'pageClass' => PriceListResource\Pages\EditPriceList::class,
            ]);

        $this->assertSame(
            'itemsRelationManager',
            $component->instance()->getTable()->getQueryStringIdentifier(),
        );
        $this->assertSame(
            'itemsRelationManagerTableSearch',
            $component->instance()->getIdentifiedTableQueryStringPropertyNameFor('tableSearch'),
        );

        $editUrl = PriceListResource::getUrl('edit', ['record' => $list]);
        $searchParam = 'itemsRelationManagerTableSearch';
        $urlWithSearch = $editUrl.'?'.$searchParam.'='.urlencode('DEEP-LINK-SKU');

        $response = $this->actingAs($admin)->get($urlWithSearch);
        $response->assertOk();

        // RelationManager is a nested Livewire component — plain ?tableSearch= on the
        // parent EditRecord page does not bind; the prefixed key is required.
        $this->assertStringNotContainsString('tableSearch=DEEP-LINK-SKU', $urlWithSearch);
        $this->assertStringContainsString($searchParam.'=DEEP-LINK-SKU', $urlWithSearch);
    }

    public function test_plain_table_search_query_param_does_not_bind_to_relation_manager(): void
    {
        $workspace = $this->defaultWorkspace();
        $variant = $this->createVariant($workspace);
        $variant->update(['sku' => 'PLAIN-SEARCH-SKU']);
        $list = $this->createPriceList($workspace);
        $item = $this->createPriceListItem($list, $variant, 50.00);

        $admin = User::query()->create([
            'name' => 'Plain Search Admin',
            'email' => 'plainsearch-'.uniqid().'@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $editUrl = PriceListResource::getUrl('edit', ['record' => $list]);

        Livewire::actingAs($admin)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $list,
                'pageClass' => PriceListResource\Pages\EditPriceList::class,
            ])
            ->set('tableSearch', 'PLAIN-SEARCH-SKU')
            ->assertCanSeeTableRecords([$item]);

        // Visiting parent page with ?tableSearch= does not pre-fill the nested manager.
        $response = $this->actingAs($admin)->get($editUrl.'?tableSearch=PLAIN-SEARCH-SKU');
        $response->assertOk();
        $this->assertStringNotContainsString('wire:initial-data', $response->getContent());
    }
}
