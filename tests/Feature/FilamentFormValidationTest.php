<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\PriceInspector;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\Pages\CreatePriceList;
use App\Filament\Resources\PriceListResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class FilamentFormValidationTest extends TestCase
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
            'name' => 'Validation Admin',
            'email' => 'validation-admin@babypark.ua',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[DataProvider('requiredLocaleProvider')]
    public function test_short_required_message_is_localized(string $locale, string $expected): void
    {
        App::setLocale($locale);

        $component = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => null,
                'variant_id' => null,
            ])
            ->call('resolvePrice')
            ->assertHasFormErrors(['customer_id' => 'required', 'variant_id' => 'required']);

        $messages = implode(' ', $component->errors()->all());

        $this->assertStringContainsString($expected, $messages);
        $this->assertStringNotContainsString('validation.required', $messages);
        $this->assertStringNotContainsString(':attribute', $messages);
    }

    public static function requiredLocaleProvider(): array
    {
        return [
            'uk' => ['uk', 'Заповніть це поле.'],
            'ru' => ['ru', 'Заполните это поле.'],
            'en' => ['en', 'Please fill in this field.'],
        ];
    }

    #[DataProvider('requiredLocaleProvider')]
    public function test_text_input_stale_error_clears_independently(string $locale, string $expectedMessage): void
    {
        App::setLocale($locale);

        $component = Livewire::actingAs($this->admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => '',
                'email' => 'still-missing@example.com',
                'role' => UserRole::Manager->value,
                'password' => 'secret',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertStringContainsString($expectedMessage, (string) $component->errors()->first('data.name'));

        $component
            ->set('data.name', 'Новий користувач')
            ->assertHasNoFormErrors(['name']);

        $this->assertTrue($component->errors()->has('data.email'));
    }

    #[DataProvider('requiredLocaleProvider')]
    public function test_select_stale_error_clears_independently(string $locale, string $expectedMessage): void
    {
        App::setLocale($locale);

        $component = Livewire::actingAs($this->admin)
            ->test(CreatePriceList::class)
            ->fillForm([
                'name' => 'Test list',
                'priority' => 1,
                'status' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['status' => 'required']);

        $this->assertStringContainsString($expectedMessage, (string) $component->errors()->first('data.status'));

        $component
            ->set('data.status', 'active')
            ->assertHasNoFormErrors(['status']);

        $this->assertFalse($component->errors()->has('data.name'));
    }

    #[DataProvider('requiredLocaleProvider')]
    public function test_searchable_select_stale_error_clears_independently(string $locale, string $expectedMessage): void
    {
        App::setLocale($locale);

        $customer = $this->createCustomer($this->workspace);
        $variant = $this->createVariant($this->workspace);

        $component = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => null,
                'variant_id' => null,
            ])
            ->call('resolvePrice')
            ->assertHasFormErrors(['customer_id' => 'required', 'variant_id' => 'required']);

        $this->assertStringContainsString($expectedMessage, (string) $component->errors()->first('data.customer_id'));

        $component
            ->set('data.customer_id', (string) $customer->id)
            ->assertHasNoFormErrors(['customer_id']);

        $this->assertTrue($component->errors()->has('data.variant_id'));

        $component
            ->set('data.variant_id', (string) $variant->id)
            ->assertHasNoFormErrors(['variant_id']);
    }

    #[DataProvider('requiredLocaleProvider')]
    public function test_conditionally_required_field_stale_error_clears_independently(string $locale, string $expectedMessage): void
    {
        App::setLocale($locale);

        $component = Livewire::actingAs($this->admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Test User',
                'email' => 'conditional@example.com',
                'role' => UserRole::Manager->value,
                'password' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        $this->assertStringContainsString($expectedMessage, (string) $component->errors()->first('data.password'));

        $component
            ->set('data.password', 'new-password')
            ->assertHasNoFormErrors(['password']);

        $this->assertFalse($component->errors()->has('data.name'));
    }

    #[DataProvider('requiredLocaleProvider')]
    public function test_searchable_select_in_table_action_clears_stale_error(string $locale, string $expectedMessage): void
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
                'product_variant_id' => null,
                'quantity_min' => 1,
                'price' => 10,
                'status' => 'active',
            ])
            ->assertHasTableActionErrors(['product_variant_id' => 'required']);

        $this->assertStringContainsString($expectedMessage, (string) $component->errors()->first('mountedActions.0.data.product_variant_id'));

        $component
            ->set('mountedActions.0.data.product_variant_id', (string) $variant->id);

        $this->assertFalse($component->errors()->has('mountedActions.0.data.product_variant_id'));
    }

    public function test_panel_forms_render_with_novalidate(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertSee('novalidate', false);

        $this->actingAs($this->admin)
            ->get('/admin/price-inspector')
            ->assertOk()
            ->assertSee('novalidate', false);

        $this->get('/cabinet/login')
            ->assertOk()
            ->assertSee('novalidate', false);
    }

    public function test_table_action_modal_form_renders_with_novalidate(): void
    {
        $list = $this->createPriceList($this->workspace);

        $component = Livewire::actingAs($this->admin)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $list,
                'pageClass' => PriceListResource\Pages\EditPriceList::class,
            ])
            ->mountTableAction('create');

        $component
            ->assertSet('mountedActions.0.name', 'create')
            ->assertDispatched('open-modal', id: $component->id().'-table-action');

        $mountedAction = $component->instance()->getMountedAction();
        $this->assertNotNull($mountedAction);
        $this->assertSame('create', $mountedAction->getName());

        $html = $component->getMountedActionModalHtml();

        $this->assertMatchesRegularExpression(
            '/<form\b(?=[^>]*\bnovalidate\b)(?=[^>]*\bwire:submit(?:\.prevent)?="callMountedAction")[^>]*>/',
            $html,
        );
        $this->assertStringContainsString('Товар / варіант', $html);
    }

    public function test_validation_errors_are_associated_with_fields_in_dom(): void
    {
        $html = Livewire::actingAs($this->admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => '',
                'email' => '',
                'role' => null,
                'password' => '',
            ])
            ->call('create')
            ->html();

        $this->assertStringContainsString('novalidate', $html);
        $this->assertStringContainsString('data-validation-error', $html);
        $this->assertStringContainsString('data-field-wrapper', $html);
        $this->assertStringContainsString(__('validation.required', [], 'uk'), $html);

        $this->assertMatchesRegularExpression('/<label[^>]+for="data\.name[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="data\.name[^"]*"/', $html);
    }

    public function test_price_inspector_quantity_error_clears_without_affecting_other_fields(): void
    {
        $customer = $this->createCustomer($this->workspace);

        $component = Livewire::actingAs($this->admin)
            ->test(PriceInspector::class)
            ->fillForm([
                'customer_id' => null,
                'variant_id' => null,
            ])
            ->set('data.quantity', 0)
            ->call('resolvePrice')
            ->assertHasFormErrors(['customer_id' => 'required', 'variant_id' => 'required']);

        $this->assertTrue($component->errors()->has('data.quantity'));

        $component
            ->set('data.quantity', 5)
            ->assertHasNoFormErrors(['quantity']);

        $this->assertTrue($component->errors()->has('data.customer_id'));
        $this->assertTrue($component->errors()->has('data.variant_id'));

        $component
            ->set('data.customer_id', (string) $customer->id)
            ->assertHasNoFormErrors(['customer_id']);

        $this->assertTrue($component->errors()->has('data.variant_id'));
    }
}
