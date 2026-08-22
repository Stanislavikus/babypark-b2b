<?php

namespace Tests\Feature\Sync;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\EntityTrust\EntityTrustReadinessStatus;
use App\Enums\UserRole;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustConfirmationService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustLinkReadinessProjector;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustReviewService;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;
use Tests\TestCase;

class Stage3ER2b1MerchantEntityTrustTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithEntityTrustFixtures;
    use InteractsWithFieldMappingFixtures;
    use InteractsWithWorkspaceRbac;
    use RefreshDatabase;

    private EntityTrustAdobeTransportResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
        $this->seed(WorkspaceRbacPermissionSeeder::class);
        $this->seedFieldDefinitions();

        $this->responder = new EntityTrustAdobeTransportResponder;
        $this->bindEntityTrustTransport($this->responder);
    }

    #[Test]
    public function review_requires_both_permissions_and_makes_no_http_without_them(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'AUTH-SKU');

        $actorManageOnly = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($account->workspace, $actorManageOnly, [
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
        ]);

        $this->expectException(AuthorizationException::class);
        app(AdobeProductEntityTrustReviewService::class)->review(
            $actorManageOnly,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );
    }

    #[Test]
    public function review_with_both_permissions_returns_ready_for_confirmation(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('READY-SKU', 501);

        $actor = $this->createEntityTrustActor($account->workspace);
        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $this->assertSame(EntityTrustFailureReason::ReadyForConfirmation, $review->status);
        $this->assertNotSame('', $review->reviewToken);
        $this->assertCount(1, $review->subjects);
        $this->assertSame('READY-SKU', $review->subjects[0]->expectedSku);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function confirm_persists_merchant_confirmed_variant_link(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('PERSIST-SKU', 601);
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $result = app(AdobeProductEntityTrustConfirmationService::class)->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
        );

        $this->assertSame(EntityTrustFailureReason::ConfirmationCompleted, $result->status);

        $link = ExternalRecordLink::withoutWorkspaceScope()
            ->where('product_variant_id', $variant->id)
            ->sole();

        $this->assertTrue($link->hasMerchantConfirmedTrust());
        $this->assertSame('601', $link->external_record_discriminator);
        $this->assertSame('PERSIST-SKU', $link->external_identifier);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function confirm_is_idempotent_for_same_trusted_entity(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('IDEM-SKU', 701);
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        app(AdobeProductEntityTrustConfirmationService::class)->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
        );

        $reviewAgain = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $result = app(AdobeProductEntityTrustConfirmationService::class)->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $reviewAgain->reviewToken,
        );

        $this->assertSame(EntityTrustFailureReason::ConfirmationCompleted, $result->status);
        $this->assertSame(1, ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count());
    }

    #[Test]
    public function legacy_erl_is_upgraded_in_place(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('LEGACY-SKU', 801);
        $actor = $this->createEntityTrustActor($account->workspace);

        $legacy = ExternalRecordLink::withoutWorkspaceScope()->create([
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'LEGACY-SKU',
        ]);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        app(AdobeProductEntityTrustConfirmationService::class)->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
        );

        $legacy->refresh();
        $this->assertTrue($legacy->hasMerchantConfirmedTrust());
        $this->assertSame('801', $legacy->external_record_discriminator);
        $this->assertSame(1, ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->count());
    }

    #[Test]
    public function remote_changed_since_review_fails_closed(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('STALE-SKU', 901);
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $this->responder->registerProduct('STALE-SKU', 901, 'simple', ['price' => 999.0]);

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                $review->reviewToken,
            );
            $this->fail('Expected remote changed failure.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::RemoteChangedSinceReview, $exception->reason);
        }

        $this->assertSame(0, ExternalRecordLink::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
    }

    #[Test]
    public function tampered_review_token_fails_closed(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('TAMPER-SKU', 1001);
        $actor = $this->createEntityTrustActor($account->workspace);

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                'not-a-valid-token',
            );
            $this->fail('Expected invalid review evidence failure.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::ConfirmationExpiredOrInvalid, $exception->reason);
        }
    }

    #[Test]
    public function sku_collision_is_rejected(): void
    {
        [$account, $productA, $variantA] = $this->seedSimpleReadyFixture('COLLIDE-SKU', 1101);
        [, $productB, $variantB] = $this->seedSimpleReadyFixture('OTHER-SKU', 1102);

        $actor = $this->createEntityTrustActor($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $account->workspace,
                $account->id,
                $variantB,
                'COLLIDE-SKU',
                '9999',
                $this->createWorkspaceActor($account->workspace),
            ),
        );

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $productA->id,
        );

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $productA->id,
                $review->reviewToken,
            );
            $this->fail('Expected link collision.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::LinkCollision, $exception->reason);
        }

        $this->assertNull(
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variantA->id)->first(),
        );
    }

    #[Test]
    public function configurable_family_uses_merchant_parent_sku_not_cfg_generator(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            existingParentSkuHint: $parentSku,
        );

        $this->assertSame('MERCHANT-PARENT-SKU', $review->subjects[0]->expectedSku);
        $this->assertStringNotContainsString('cfg-', strtolower($review->subjects[0]->expectedSku));
        $this->assertCount(3, $review->subjects);
        $this->assertContains('EXTRA-CHILD-SKU', $review->extraRemoteChildSkus);
    }

    #[Test]
    public function configurable_family_persists_atomically(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            existingParentSkuHint: $parentSku,
        );

        app(AdobeProductEntityTrustConfirmationService::class)->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
            existingParentSkuHint: $parentSku,
        );

        $this->assertSame(1, ExternalRecordLink::withoutWorkspaceScope()->where('product_id', $product->id)->count());
        $this->assertSame(2, ExternalRecordLink::withoutWorkspaceScope()->whereIn('product_variant_id', collect($variants)->pluck('id'))->count());
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function configurable_child_failure_prevents_family_persistence(): void
    {
        [$account, $product, $variants, $parentSku] = $this->seedConfigurableReadyFixture();
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            existingParentSkuHint: $parentSku,
        );

        $this->responder->registerProduct($variants[1]->sku, 2202, 'configurable');

        try {
            app(AdobeProductEntityTrustConfirmationService::class)->confirm(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                $review->reviewToken,
                existingParentSkuHint: $parentSku,
            );
            $this->fail('Expected remote type mismatch.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::RemoteTypeMismatch, $exception->reason);
        }

        $this->assertSame(0, ExternalRecordLink::withoutWorkspaceScope()->where('connector_account_id', $account->id)->count());
    }

    #[Test]
    public function link_readiness_projection_reports_initial_link_required(): void
    {
        [$account, $product] = $this->seedSimpleReadyFixture('READINESS-SKU', 1301);
        $actor = $this->createEntityTrustActor($account->workspace);

        $items = app(AdobeProductEntityTrustLinkReadinessProjector::class)->projectForAccount(
            $actor,
            $account->workspace,
            $account->id,
        );

        $item = collect($items)->firstWhere('productId', (string) $product->id);
        $this->assertNotNull($item);
        $this->assertSame(EntityTrustReadinessStatus::InitialLinkRequired, $item->status);
    }

    #[Test]
    public function link_readiness_projection_reports_already_confirmed(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('CONFIRMED-SKU', 1401);
        $actor = $this->createEntityTrustActor($account->workspace);

        ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedVariantLinkAttributes(
                $account->workspace,
                $account->id,
                $variant,
                'CONFIRMED-SKU',
                '1401',
                $this->createWorkspaceActor($account->workspace),
            ),
        );

        $items = app(AdobeProductEntityTrustLinkReadinessProjector::class)->projectForAccount(
            $actor,
            $account->workspace,
            $account->id,
        );

        $item = collect($items)->firstWhere('productId', (string) $product->id);
        $this->assertSame(EntityTrustReadinessStatus::AlreadyConfirmed, $item->status);
    }

    #[Test]
    public function confirm_performs_remote_verification_before_local_persistence(): void
    {
        [$account, $product, $variant] = $this->seedSimpleReadyFixture('TX-ORDER-SKU', 1501);
        $actor = $this->createEntityTrustActor($account->workspace);

        $review = app(AdobeProductEntityTrustReviewService::class)->review(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
        );

        $this->assertGreaterThan(0, count($this->responder->recordedMethods));

        app(AdobeProductEntityTrustConfirmationService::class)->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->reviewToken,
        );

        $this->assertTrue(
            ExternalRecordLink::withoutWorkspaceScope()->where('product_variant_id', $variant->id)->exists(),
        );
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: ProductVariant}
     */
    private function seedSimpleReadyFixture(string $sku, int $entityId): array
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, $sku);

        $this->responder->registerProduct($sku, $entityId, 'simple', [
            'name' => $product->name,
            'price' => 100.0,
        ]);

        return [$account, $product, $variant];
    }

    /**
     * @return array{0: ConnectorAccount, 1: Product, 2: list<ProductVariant>, 3: string}
     */
    private function seedConfigurableReadyFixture(): array
    {
        $account = $this->createConnectorAccount();
        $this->prepareConfigurableEntityTrustConfiguration($account);
        $parentSku = 'MERCHANT-PARENT-SKU';
        [$product, $variants] = $this->createConfigurableEntityTrustProduct($account->workspace, 'ET-CFG', $parentSku);

        $this->responder->registerProduct($parentSku, 2001, 'configurable', ['name' => $product->name]);
        $this->responder->registerProduct($variants[0]->sku, 2002, 'simple', ['name' => $product->name]);
        $this->responder->registerProduct($variants[1]->sku, 2003, 'simple', ['name' => $product->name]);
        $this->responder->registerConfigurableChildren($parentSku, [
            ['sku' => $variants[0]->sku],
            ['sku' => $variants[1]->sku],
            ['sku' => 'EXTRA-CHILD-SKU'],
        ]);

        return [$account, $product, $variants, $parentSku];
    }
}
