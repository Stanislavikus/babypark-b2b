<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MasterProductPhysicalShippingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->where('is_default', true)->sole();
        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function manual_product_saves_physical_dimensions_and_packaging_in_the_master_card(): void
    {
        $product = $this->product();

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'net_weight' => '5.250',
                'gross_weight' => '6.100',
                'width_mm' => 610,
                'height_mm' => 1040,
                'depth_mm' => 870,
                'volume_m3' => '0.552114',
                'package_quantity' => 1,
                'package_type' => 'коробка',
                'units_per_box' => 2,
                'boxes_per_pallet' => 18,
                'lead_time_days' => 4,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $product->fresh();

        $this->assertSame('5.250', (string) $fresh->net_weight);
        $this->assertSame('6.100', (string) $fresh->gross_weight);
        $this->assertSame(610, (int) $fresh->width_mm);
        $this->assertSame(1040, (int) $fresh->height_mm);
        $this->assertSame(870, (int) $fresh->depth_mm);
        $this->assertSame('0.552114', (string) $fresh->volume_m3);
        $this->assertSame(1, (int) $fresh->package_quantity);
        $this->assertSame('коробка', $fresh->package_type);
        $this->assertSame(2, (int) $fresh->units_per_box);
        $this->assertSame(18, (int) $fresh->boxes_per_pallet);
        $this->assertSame(4, (int) $fresh->lead_time_days);
    }

    #[Test]
    public function source_owned_product_keeps_physical_and_packaging_fields_read_only(): void
    {
        $product = $this->product([
            'onec_guid' => (string) Str::uuid(),
            'net_weight' => '3.500',
            'package_quantity' => 6,
            'package_type' => 'ящик',
            'lead_time_days' => 2,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldDisabled('net_weight')
            ->assertFormFieldDisabled('package_quantity')
            ->assertFormFieldDisabled('package_type')
            ->assertFormFieldDisabled('lead_time_days')
            ->fillForm([
                'net_weight' => '9.999',
                'package_quantity' => 99,
                'package_type' => 'override',
                'lead_time_days' => 99,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $product->fresh();

        $this->assertSame('3.500', (string) $fresh->net_weight);
        $this->assertSame(6, (int) $fresh->package_quantity);
        $this->assertSame('ящик', $fresh->package_type);
        $this->assertSame(2, (int) $fresh->lead_time_days);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function product(array $overrides = []): Product
    {
        $product = Product::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'name' => 'Physical Master product',
            'is_active' => true,
        ], $overrides));

        ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        return $product;
    }
}
