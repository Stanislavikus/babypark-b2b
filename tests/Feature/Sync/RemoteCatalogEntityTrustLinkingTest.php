<?php

namespace Tests\Feature\Sync;

use App\Enums\EntityTrust\EntityTrustFailureReason;
use App\Enums\ExternalRecordLinkTrustOrigin;
use App\Enums\SyncDataDomain;
use App\Enums\UserRole;
use App\Filament\Pages\Sync\ManageAdobeRemoteCatalog;
use App\Models\ConnectorAccount;
use App\Models\ExternalRecordLink;
use App\Models\RemoteCatalogSnapshotItem;
use App\Models\WorkspaceUser;
use App\Services\Connectors\AdobeRemoteCatalogProjectionService;
use App\Services\Connectors\RemoteCatalogScanService;
use App\Services\Sync\EntityTrust\AdobeProductEntityTrustReviewService;
use App\Services\Sync\EntityTrust\AdobeRemoteCatalogEntityTrustService;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshotResolver;
use App\Support\Connectors\RemoteCatalog\RemoteCatalogItemCandidate;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\InteractsWithEntityTrustFixtures;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Concerns\InteractsWithWorkspaceRbac;
use Tests\Support\Sync\EntityTrust\EntityTrustAdobeTransportResponder;
use Tests\TestCase;

class RemoteCatalogEntityTrustLinkingTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function simple_remote_row_links_only_after_existing_entity_trust_confirmation(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, 'REMOTE-LINK-SKU');
        $actor = $this->createEntityTrustActor($account->workspace);
        $this->responder->registerProduct('REMOTE-LINK-SKU', 501, 'simple', ['name' => $product->name]);
        $item = $this->publishRemoteItem($account, '501', 'REMOTE-LINK-SKU', 'Remote simple', 'simple');
        $service = app(AdobeRemoteCatalogEntityTrustService::class);

        $candidates = $service->candidateProducts(
            $actor,
            $account->workspace,
            $account->id,
            (string) $item->id,
        );

        $this->assertSame([(string) $product->id], array_column($candidates, 'id'));

        $review = $service->requestReview(
            $actor,
            $account->workspace,
            $account->id,
            (string) $item->id,
            (string) $product->id,
        );

        $this->assertSame(EntityTrustFailureReason::ReadyForConfirmation, $review->reason);
        $this->assertNotNull($review->review_flow_id);
        $this->assertDatabaseCount('external_record_links', 0);

        $confirmed = $service->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->review_flow_id,
        );

        $this->assertSame(EntityTrustFailureReason::ConfirmationCompleted, $confirmed->reason);

        $link = ExternalRecordLink::withoutWorkspaceScope()
            ->where('product_variant_id', $variant->id)
            ->sole();

        $this->assertSame('REMOTE-LINK-SKU', $link->external_identifier);
        $this->assertSame('501', $link->external_record_discriminator);
        $this->assertSame(ExternalRecordLinkTrustOrigin::MerchantConfirmed->value, $link->trust_origin);
        $this->assertNotNull($link->established_by_workspace_user_id);
        $this->assertNotNull($link->established_at);

        $summary = app(AdobeRemoteCatalogProjectionService::class)->summary($account);
        $this->assertSame(1, $summary->linkedCount);
        $this->assertSame(0, $summary->remoteOnlyCount);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function selected_remote_row_must_match_fresh_magento_logical_entity_id(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'IDENTITY-SKU');
        $actor = $this->createEntityTrustActor($account->workspace);
        $this->responder->registerProduct('IDENTITY-SKU', 999, 'simple', ['name' => $product->name]);
        $item = $this->publishRemoteItem($account, '501', 'IDENTITY-SKU', 'Remote identity', 'simple');

        $outcome = app(AdobeRemoteCatalogEntityTrustService::class)->requestReview(
            $actor,
            $account->workspace,
            $account->id,
            (string) $item->id,
            (string) $product->id,
        );

        $this->assertSame(EntityTrustFailureReason::CandidateUntrusted, $outcome->reason);
        $this->assertNull($outcome->review_flow_id);
        $this->assertDatabaseCount('external_record_links', 0);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function simple_remote_row_rejects_master_product_with_different_variant_sku(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'LOCAL-OTHER-SKU');
        $actor = $this->createEntityTrustActor($account->workspace);
        $item = $this->publishRemoteItem($account, '601', 'REMOTE-ONLY-SKU', 'Remote only', 'simple');
        $service = app(AdobeRemoteCatalogEntityTrustService::class);

        $this->assertSame([], $service->candidateProducts(
            $actor,
            $account->workspace,
            $account->id,
            (string) $item->id,
        ));

        $this->expectException(EntityTrustException::class);

        try {
            $service->requestReview(
                $actor,
                $account->workspace,
                $account->id,
                (string) $item->id,
                (string) $product->id,
            );
        } finally {
            $this->assertDatabaseCount('external_record_links', 0);
            $this->assertFalse($this->responder->hasConsequentialWrite());
        }
    }

    #[Test]
    public function stale_remote_item_id_cannot_start_entity_trust_review(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'STALE-SKU');
        $actor = $this->createEntityTrustActor($account->workspace);
        $this->responder->registerProduct('STALE-SKU', 701, 'simple', ['name' => $product->name]);
        $staleItem = $this->publishRemoteItem($account, '701', 'STALE-SKU', 'Old row', 'simple');
        $this->publishRemoteItem($account, '702', 'NEW-SKU', 'New row', 'simple');

        try {
            app(AdobeRemoteCatalogEntityTrustService::class)->requestReview(
                $actor,
                $account->workspace,
                $account->id,
                (string) $staleItem->id,
                (string) $product->id,
            );
            $this->fail('Expected stale remote catalogue item to be rejected.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::CandidateNotFound, $exception->reason);
        }

        $this->assertDatabaseCount('external_record_links', 0);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function configurable_remote_parent_can_link_existing_master_family_through_entity_trust(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareConfigurableEntityTrustConfiguration($account);
        [$product, $variants] = $this->createConfigurableEntityTrustProduct($account->workspace, 'LOCAL-CFG');
        $actor = $this->createEntityTrustActor($account->workspace);
        $parentSku = 'REMOTE-CFG-PARENT';

        $this->responder->registerProduct($parentSku, 801, 'configurable', ['name' => $product->name]);
        $this->responder->registerProduct($variants[0]->sku, 802, 'simple', ['name' => $product->name]);
        $this->responder->registerProduct($variants[1]->sku, 803, 'simple', ['name' => $product->name]);
        $this->responder->registerConfigurableChildren($parentSku, [
            ['sku' => $variants[0]->sku],
            ['sku' => $variants[1]->sku],
        ]);
        $item = $this->publishRemoteItem($account, '801', $parentSku, 'Remote configurable', 'configurable');
        $service = app(AdobeRemoteCatalogEntityTrustService::class);

        $review = $service->requestReview(
            $actor,
            $account->workspace,
            $account->id,
            (string) $item->id,
            (string) $product->id,
        );

        $this->assertSame(EntityTrustFailureReason::ReadyForConfirmation, $review->reason);
        $this->assertNotNull($review->review_flow_id);

        $confirmed = $service->confirm(
            $actor,
            $account->workspace,
            $account->id,
            (string) $product->id,
            $review->review_flow_id,
        );

        $this->assertSame(EntityTrustFailureReason::ConfirmationCompleted, $confirmed->reason);
        $this->assertDatabaseHas('external_record_links', [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_id' => $product->id,
            'external_identifier' => $parentSku,
            'external_record_discriminator' => '801',
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
        ]);
        $this->assertSame(2, ExternalRecordLink::withoutWorkspaceScope()
            ->whereIn('product_variant_id', collect($variants)->pluck('id'))
            ->count());

        $summary = app(AdobeRemoteCatalogProjectionService::class)->summary($account);
        $this->assertSame(1, $summary->linkedCount);
        $this->assertSame(0, $summary->remoteOnlyCount);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function merchant_can_review_and_confirm_remote_row_from_remote_catalogue_surface(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product, $variant] = $this->createSimpleEntityTrustProduct($account->workspace, 'UI-LINK-SKU');
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);
        $this->responder->registerProduct('UI-LINK-SKU', 901, 'simple', ['name' => $product->name]);
        $item = $this->publishRemoteItem($account, '901', 'UI-LINK-SKU', 'Remote UI product', 'simple');

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeRemoteCatalog::class, ['account' => $account->id])
            ->assertSet('entityTrustCanReviewOrConfirm', true)
            ->assertTableActionVisible('linkMasterProduct', $item)
            ->assertSee('Remote UI product');

        $component
            ->callTableAction('linkMasterProduct', $item, data: ['product_id' => (string) $product->id])
            ->assertSet('entityTrustProductId', (string) $product->id)
            ->assertSet('entityTrustOutcomeReadyForConfirmation', true)
            ->assertSet('entityTrustActiveReviewProductId', (string) $product->id)
            ->assertSee(__('entity_trust.failure.ready_for_confirmation.label'))
            ->assertSee('UI-LINK-SKU');

        $this->assertDatabaseMissing('external_record_links', [
            'product_variant_id' => $variant->id,
        ]);

        $component
            ->call('confirmEntityTrust')
            ->assertSet('entityTrustOutcomeReadyForConfirmation', false)
            ->assertSet('entityTrustReviewFlowId', null)
            ->assertSet('linkedRemoteCount', 1)
            ->assertSet('remoteOnlyCount', 0)
            ->assertDontSee('Remote UI product');

        $this->assertDatabaseHas('external_record_links', [
            'workspace_id' => $account->workspace_id,
            'connector_account_id' => $account->id,
            'product_variant_id' => $variant->id,
            'external_identifier' => 'UI-LINK-SKU',
            'external_record_discriminator' => '901',
            'trust_origin' => ExternalRecordLinkTrustOrigin::MerchantConfirmed->value,
        ]);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function remote_catalogue_link_action_is_hidden_without_live_permission(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'NO-LIVE-SKU');
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
        ]);
        $this->responder->registerProduct('NO-LIVE-SKU', 902, 'simple', ['name' => $product->name]);
        $item = $this->publishRemoteItem($account, '902', 'NO-LIVE-SKU', 'No live permission', 'simple');

        Livewire::actingAs($actor)
            ->test(ManageAdobeRemoteCatalog::class, ['account' => $account->id])
            ->assertSet('entityTrustCanReviewOrConfirm', false)
            ->assertTableActionHidden('linkMasterProduct', $item);

        $this->assertDatabaseCount('external_record_links', 0);
        $this->assertFalse($this->responder->hasConsequentialWrite());
    }

    #[Test]
    public function permission_revocation_hides_link_action_and_backend_rejects_review_before_magento_read(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'REVOKED-SKU');
        $actor = $this->createStaffUser(UserRole::Manager);
        $this->grantExactWorkspacePermissions($account->workspace, $actor, [
            WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
            WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
            WorkspacePermissions::RUN_SYNC_PREVIEW,
            WorkspacePermissions::RUN_SYNC_LIVE,
        ]);
        $this->responder->registerProduct('REVOKED-SKU', 903, 'simple', ['name' => $product->name]);
        $item = $this->publishRemoteItem($account, '903', 'REVOKED-SKU', 'Revoked permission', 'simple');

        $component = Livewire::actingAs($actor)
            ->test(ManageAdobeRemoteCatalog::class, ['account' => $account->id])
            ->assertTableActionVisible('linkMasterProduct', $item);

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('user_id', $actor->id)
            ->sole();
        $this->revokeAllWorkspaceRoles($membership);
        $replacementRole = $this->createRoleWithPermissions(
            $account->workspace_id,
            'Remote catalogue without live '.$actor->id,
            [
                WorkspacePermissions::VIEW_CONNECTOR_ACCOUNTS,
                WorkspacePermissions::MANAGE_SYNC_CONFIGURATIONS,
                WorkspacePermissions::RUN_SYNC_PREVIEW,
            ],
        );
        $this->assignRoleToMembership($membership, $replacementRole);

        $component
            ->call('$refresh')
            ->assertSet('entityTrustCanReviewOrConfirm', false)
            ->assertTableActionHidden('linkMasterProduct', $item);

        $methodsBefore = $this->responder->recordedMethods;

        try {
            app(AdobeRemoteCatalogEntityTrustService::class)->requestReview(
                $actor,
                $account->workspace,
                $account->id,
                (string) $item->id,
                (string) $product->id,
            );
            $this->fail('Expected revoked Entity Trust permission to reject review.');
        } catch (AuthorizationException) {
            $this->assertSame($methodsBefore, $this->responder->recordedMethods);
        }

        $this->assertDatabaseCount('external_record_links', 0);
    }

    #[Test]
    public function expected_remote_catalog_target_mismatch_rejects_review_before_magento_read(): void
    {
        $account = $this->createConnectorAccount();
        $this->prepareEntityTrustConfiguration($account);
        [$product] = $this->createSimpleEntityTrustProduct($account->workspace, 'TARGET-BIND-SKU');
        $actor = $this->createEntityTrustActor($account->workspace);
        $expectedTarget = app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account);

        $account->base_url = 'https://changed-target.example.com';
        $account->save();
        $account->refresh();

        try {
            app(AdobeProductEntityTrustReviewService::class)->review(
                $actor,
                $account->workspace,
                $account->id,
                (string) $product->id,
                expectedPrimaryLogicalEntityId: 901,
                expectedTargetSnapshot: $expectedTarget,
            );
            $this->fail('Expected remote catalogue target mismatch.');
        } catch (EntityTrustException $exception) {
            $this->assertSame(EntityTrustFailureReason::ReviewTargetMismatch, $exception->reason);
        }

        $this->assertSame([], $this->responder->recordedMethods);
        $this->assertDatabaseCount('external_record_links', 0);
    }

    private function publishRemoteItem(
        ConnectorAccount $account,
        string $remoteIdentifier,
        string $sku,
        string $name,
        string $type,
    ): RemoteCatalogSnapshotItem {
        $scan = app(RemoteCatalogScanService::class)->begin(
            $account,
            SyncDataDomain::Products,
            app(AdobeConnectorAccountTargetSnapshotResolver::class)->resolve($account)->toEnvelopeArray(),
            1,
        );

        app(RemoteCatalogScanService::class)->append($scan, [
            new RemoteCatalogItemCandidate(
                $remoteIdentifier,
                $sku,
                $name,
                $type,
            ),
        ]);
        app(RemoteCatalogScanService::class)->publish($scan);

        return RemoteCatalogSnapshotItem::withoutWorkspaceScope()
            ->where('workspace_id', $account->workspace_id)
            ->where('snapshot_id', app(AdobeRemoteCatalogProjectionService::class)
                ->currentSnapshot($account)?->id)
            ->where('remote_identifier', $remoteIdentifier)
            ->sole();
    }
}
