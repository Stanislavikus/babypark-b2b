<?php

namespace Tests\Feature;

use App\Enums\PriceDisplayMode;
use App\Enums\UserRole;
use App\Filament\Pages\PriceInspector;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\RelationManagers\ItemsRelationManager;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pricing\ProductPricingSummary;
use App\Support\Pricing\CustomerFacingPriceLabel;
use App\Support\Workspace\WorkspaceContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class PriceInspectorLocalizationTest extends TestCase
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
            'name' => 'Locale Admin',
            'email' => 'locale-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @dataProvider localeExpectationsProvider
     */
    public function test_price_inspector_shows_expected_locale_labels(string $locale, array $expected, array $forbidden): void
    {
        App::setLocale($locale);

        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);
        $variant->product->update(['name' => 'Test product']);
        $list = $this->createPriceList($this->workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 90.0, vatRate: 20.0);

        $html = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->call('resolvePrice')
            ->html();

        foreach ($expected as $label) {
            $this->assertStringContainsString($label, $html, "Expected [{$label}] for locale [{$locale}]");
        }

        foreach ($forbidden as $label) {
            $this->assertStringNotContainsString($label, $html, "Forbidden [{$label}] for locale [{$locale}]");
        }

        $this->assertStringNotContainsString('price_inspector.headline', $html);
        $this->assertStringNotContainsString('price_display.with_tax', $html);
    }

    public static function localeExpectationsProvider(): array
    {
        return [
            'uk' => [
                'uk',
                [
                    'Ціна знайдена',
                    'Як система перевірила ціну',
                    'Перевірити ціну',
                    'з податком',
                ],
                [
                    'Price found',
                    'How the system checked the price',
                    'Check price',
                    'incl. tax',
                    'с налогом',
                ],
            ],
            'ru' => [
                'ru',
                [
                    'Цена найдена',
                    'Как система проверила цену',
                    'Проверить цену',
                    'с налогом',
                ],
                [
                    'Ціна знайдена',
                    'Price found',
                    'Check price',
                    'incl. tax',
                    'з податком',
                ],
            ],
            'en' => [
                'en',
                [
                    'Price found',
                    'How the system checked the price',
                    'Check price',
                    'incl. tax',
                ],
                [
                    'Ціна знайдена',
                    'Цена найдена',
                    'Перевірити ціну',
                    'Проверить цену',
                    'з податком',
                    'с налогом',
                ],
            ],
        ];
    }

    /**
     * @dataProvider validationLocaleProvider
     */
    public function test_validation_required_is_localized(string $locale, string $expectedFragment): void
    {
        App::setLocale($locale);

        $component = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => null,
                'variant_id' => null,
                'quantity' => 1,
            ])
            ->call('resolvePrice')
            ->assertHasFormErrors(['customer_id' => 'required', 'variant_id' => 'required']);

        $messages = implode(' ', $component->errors()->all());
        $this->assertStringContainsString($expectedFragment, $messages);
        $this->assertStringNotContainsString('validation.required', $messages);
    }

    /**
     * @dataProvider validationLocaleProvider
     */
    public function test_price_list_form_validation_required_is_localized(string $locale, string $expectedFragment): void
    {
        App::setLocale($locale);

        $list = $this->createPriceList($this->workspace);
        $variant = $this->createVariant($this->workspace);

        $component = Livewire::actingAs($this->admin)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $list,
                'pageClass' => PriceListResource\Pages\EditPriceList::class,
            ])
            ->callTableAction('create', data: [
                'product_variant_id' => $variant->id,
                'quantity_min' => 1,
                'price' => null,
                'status' => 'active',
            ])
            ->assertHasTableActionErrors(['price' => 'required']);

        $messages = implode(' ', $component->errors()->all());
        $this->assertStringContainsString($expectedFragment, $messages);
        $this->assertStringNotContainsString('validation.required', $messages);
    }

    public static function validationLocaleProvider(): array
    {
        return [
            'uk' => ['uk', 'обов\'язковим'],
            'ru' => ['ru', 'обязательно'],
            'en' => ['en', 'required'],
        ];
    }

    public function test_compact_label_regression_customer_facing_label_per_locale(): void
    {
        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);
        $list = $this->createPriceList($this->workspace);
        $customer->update(['default_price_list_id' => $list->id]);
        $this->createPriceListItem($list, $variant, 90.0, vatRate: 20.0);

        $summary = app(ProductPricingSummary::class);
        $display = $summary->resolveVariantDisplay($variant, $customer);

        App::setLocale('uk');
        $this->workspace->update(['default_price_display_mode' => PriceDisplayMode::TaxInclusivePrimary]);
        app(WorkspaceContext::class)->reset();
        $this->assertSame('108,00 ₴ з податком', CustomerFacingPriceLabel::forDisplay($display));

        App::setLocale('en');
        $this->assertSame('108,00 ₴ incl. tax', CustomerFacingPriceLabel::forDisplay($display));

        App::setLocale('ru');
        $this->assertSame('108,00 ₴ с налогом', CustomerFacingPriceLabel::forDisplay($display));
    }

    public function test_compact_label_regression_product_pricing_summary_per_locale(): void
    {
        $variant = $this->createVariant($this->workspace, basePriceCache: 90.0);
        $product = $variant->product->load('variants');
        $summary = app(ProductPricingSummary::class);

        $this->workspace->update(['default_price_display_mode' => PriceDisplayMode::TaxExclusivePrimary]);
        $this->workspace->refresh();
        app(WorkspaceContext::class)->reset();

        App::setLocale('uk');
        $this->assertSame('90,00 ₴ без податку', $summary->formatDefaultSalePrice($product));

        App::setLocale('en');
        $this->assertSame('90,00 ₴ excl. tax', $summary->formatDefaultSalePrice($product));

        App::setLocale('ru');
        $this->assertSame('90,00 ₴ без налога', $summary->formatDefaultSalePrice($product));
    }
}
