<?php

namespace Tests\Feature;

use App\Enums\ProductLifecycleStatus;
use App\Enums\UserRole;
use App\Exceptions\Catalog\MasterProductLifecycleMutationException;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Catalog\MasterProductLifecycleMutationService;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\TestCase;

final class MasterProductLifecycleMutationServiceTest extends TestCase
{
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->workspace = $this->defaultWorkspace();
        $this->actor = User::factory()->create(['role' => UserRole::Admin]);

        $membership = $this->makeWorkspaceMembership($this->workspace, $this->actor);
        $role = $this->createRoleWithPermissions($this->workspace->id, 'Lifecycle manager', [
            WorkspacePermissions::MANAGE_PRODUCTS,
        ]);
        $this->assignRoleToMembership($membership, $role);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function manual_draft_can_activate_and_archive_with_legacy_boolean_kept_in_sync(): void
    {
        [$product] = $this->manualProduct(ProductLifecycleStatus::Draft);
        $service = app(MasterProductLifecycleMutationService::class);

        $active = $service->transition(
            $this->actor,
            $this->workspace,
            $product,
            ProductLifecycleStatus::Draft,
            ProductLifecycleStatus::Active,
        );

        $this->assertSame(ProductLifecycleStatus::Active, $active->lifecycle_status);
        $this->assertTrue($active->is_active);

        $archived = $service->transition(
            $this->actor,
            $this->workspace,
            $active,
            ProductLifecycleStatus::Active,
            ProductLifecycleStatus::Archived,
        );

        $this->assertSame(ProductLifecycleStatus::Archived, $archived->lifecycle_status);
        $this->assertFalse($archived->is_active);
    }

    #[Test]
    public function stale_expected_lifecycle_fails_closed_without_mutation(): void
    {
        [$product] = $this->manualProduct(ProductLifecycleStatus::Draft);

        try {
            app(MasterProductLifecycleMutationService::class)->transition(
                $this->actor,
                $this->workspace,
                $product,
                ProductLifecycleStatus::Archived,
                ProductLifecycleStatus::Active,
            );

            $this->fail('Expected stale lifecycle rejection.');
        } catch (MasterProductLifecycleMutationException $exception) {
            $this->assertSame(
                'Стан товару змінився після відкриття картки. Оновіть сторінку і повторіть дію.',
                $exception->getMessage(),
            );
        }

        $product->refresh();

        $this->assertSame(ProductLifecycleStatus::Draft, $product->lifecycle_status);
        $this->assertFalse($product->is_active);
    }

    #[Test]
    public function product_or_any_variant_source_identity_blocks_manual_lifecycle_mutation(): void
    {
        [$product] = $this->manualProduct(ProductLifecycleStatus::Draft);
        $product->update(['onec_guid' => '11111111-1111-4111-8111-111111111111']);

        $this->assertSourceOwnedRejected($product);

        [$manualProduct, $variant] = $this->manualProduct(ProductLifecycleStatus::Draft);
        $variant->update(['onec_guid' => '22222222-2222-4222-8222-222222222222']);

        $this->assertSourceOwnedRejected($manualProduct);
    }

    #[Test]
    public function master_card_lifecycle_actions_activate_and_archive_manual_product(): void
    {
        [$product] = $this->manualProduct(ProductLifecycleStatus::Draft);

        $activate = TestAction::make('activate_product');
        $archive = TestAction::make('archive_product');

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionVisible($activate)
            ->assertActionVisible($archive)
            ->callAction($activate)
            ->assertNotified('Товар активовано');

        $product->refresh();

        $this->assertSame(ProductLifecycleStatus::Active, $product->lifecycle_status);
        $this->assertTrue($product->is_active);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden($activate)
            ->assertActionVisible($archive)
            ->callAction($archive)
            ->assertNotified('Товар архівовано');

        $product->refresh();

        $this->assertSame(ProductLifecycleStatus::Archived, $product->lifecycle_status);
        $this->assertFalse($product->is_active);
    }

    #[Test]
    public function source_owned_variant_hides_manual_lifecycle_actions_in_master_card(): void
    {
        [$product, $variant] = $this->manualProduct(ProductLifecycleStatus::Draft);
        $variant->update(['onec_guid' => '33333333-3333-4333-8333-333333333333']);

        Livewire::actingAs($this->actor)
            ->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertActionHidden(
                TestAction::make('activate_product'),
            )
            ->assertActionHidden(
                TestAction::make('archive_product'),
            );
    }

    /** @return array{Product,ProductVariant} */
    private function manualProduct(ProductLifecycleStatus $status): array
    {
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'onec_guid' => null,
            'name' => 'Lifecycle test product',
            'lifecycle_status' => $status,
        ]);

        $variant = ProductVariant::withoutWorkspaceScope()->create([
            'workspace_id' => $this->workspace->id,
            'product_id' => $product->id,
            'onec_guid' => null,
            'sku' => null,
            'attributes' => [],
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function assertSourceOwnedRejected(Product $product): void
    {
        try {
            app(MasterProductLifecycleMutationService::class)->transition(
                $this->actor,
                $this->workspace,
                $product,
                ProductLifecycleStatus::Draft,
                ProductLifecycleStatus::Active,
            );

            $this->fail('Expected source-owned lifecycle rejection.');
        } catch (MasterProductLifecycleMutationException $exception) {
            $this->assertSame(
                'Для товару або варіанта з джерелом 1С стан у Master доступний лише для перегляду.',
                $exception->getMessage(),
            );
        }

        $product->refresh();

        $this->assertSame(ProductLifecycleStatus::Draft, $product->lifecycle_status);
        $this->assertFalse($product->is_active);
    }
}
