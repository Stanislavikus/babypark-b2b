<?php

namespace Tests\Feature\Sync;

use App\Enums\FieldObjectType;
use App\Enums\SyncConfigurationOperationalState;
use App\Enums\SyncDataDomain;
use App\Enums\SyncLiveOutcome;
use App\Enums\SyncRunMode;
use App\Enums\SyncSemanticOperation;
use App\Enums\UserRole;
use App\Models\ExternalRecordLink;
use App\Models\Product;
use App\Models\SyncConfiguration;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Services\Sync\FieldMappingMutationService;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveApplyException;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveApplyService;
use App\Support\Connectors\AdobePaaS\Receive\AdobeProductReceiveProposalService;
use App\Support\Connectors\Transport\ConnectorHttpResult;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Sync\Exceptions\SyncLiveAdmissionException;
use App\Support\Workspace\WorkspacePermissions;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceRbacPermissionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ConfiguresSyncSupportProfiles;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\Concerns\CreatesMerchantConfirmedExternalRecordLinks;
use Tests\Concerns\InteractsWithFieldMappingFixtures;
use Tests\Support\Connectors\RecordingConnectorHttpTransport;
use Tests\TestCase;

class AdobeProductReceiveApplyServiceTest extends TestCase
{
    use ConfiguresSyncSupportProfiles;
    use CreatesConnectorAccountFixtures;
    use CreatesMerchantConfirmedExternalRecordLinks;
    use InteractsWithFieldMappingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            WorkspaceSeeder::class,
            FieldDefinitionSeeder::class,
            ConnectorFoundationSeeder::class,
            WorkspaceRbacPermissionSeeder::class,
        ]);
        config(['cache.default' => 'file']);
        Cache::flush();
        $this->configureAdobePaaSSyncSupportProfile([
            [SyncDataDomain::Products, SyncSemanticOperation::Import, SyncRunMode::Live],
        ]);
    }

    #[Test]
    public function happy_path_applies_remote_name_and_completes_live_import_run(): void
    {
        $fixture = $this->fixture('Local Name', 'Remote Name');

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'],
            $fixture['proposal']->flowId,
            $fixture['workspace']->id,
            $fixture['account']->id,
            $fixture['configuration']->id,
            FieldObjectType::Product,
            $fixture['product']->id,
        );

        $this->assertSame('Remote Name', $fixture['product']->fresh()->name);
        $this->assertSame('completed', $run->status->value);
        $this->assertSame(SyncSemanticOperation::Import, $run->semantic_operation);
        $this->assertSame(2, $fixture['transport']->sendCount);

        $item = SyncRunItem::withoutWorkspaceScope()
            ->where('sync_run_id', $run->id)
            ->sole();
        $this->assertSame(SyncLiveOutcome::Synchronized, $item->liveOutcome());
        $this->assertSame('receive_product_name_applied', $item->findings[0]['code']);
    }

    #[Test]
    public function stale_remote_name_completes_not_applied_without_local_mutation(): void
    {
        $fixture = $this->fixture('Local Name', 'Remote Name', 'Changed Remote Name');

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'],
            $fixture['proposal']->flowId,
            $fixture['workspace']->id,
            $fixture['account']->id,
            $fixture['configuration']->id,
            FieldObjectType::Product,
            $fixture['product']->id,
        );

        $this->assertSame('Local Name', $fixture['product']->fresh()->name);
        $this->assertSame('completed', $run->status->value);
        $item = SyncRunItem::withoutWorkspaceScope()
            ->where('sync_run_id', $run->id)
            ->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
        $this->assertSame('receive_apply_remote_value_changed', $item->findings[0]['code']);
        $this->assertSame(2, $fixture['transport']->sendCount);
    }

    #[Test]
    public function stale_local_name_after_proposal_completes_not_applied(): void
    {
        $fixture = $this->fixture('Local Name', 'Remote Name');
        $fixture['product']->update(['name' => 'Changed After Proposal']);

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'],
            $fixture['proposal']->flowId,
            $fixture['workspace']->id,
            $fixture['account']->id,
            $fixture['configuration']->id,
            FieldObjectType::Product,
            $fixture['product']->id,
        );

        $this->assertSame('Changed After Proposal', $fixture['product']->fresh()->name);
        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
        $this->assertSame('receive_apply_local_value_changed', $item->findings[0]['code']);
    }

    #[Test]
    public function revoked_actor_fails_before_consume_and_same_flow_can_be_retried_after_regrant(): void
    {
        $fixture = $this->fixture('Local Name', 'Remote Name');
        $role = $fixture['membership']->roles()->sole();
        $this->revokeAllWorkspaceRoles($fixture['membership']);

        try {
            app(AdobeProductReceiveApplyService::class)->apply(
                $fixture['actor'], $fixture['proposal']->flowId,
                $fixture['workspace']->id, $fixture['account']->id,
                $fixture['configuration']->id, FieldObjectType::Product,
                $fixture['product']->id,
            );
            $this->fail('Expected authorization failure.');
        } catch (AdobeProductReceiveApplyException $exception) {
            $this->assertSame('receive_apply_not_authorized', $exception->reasonCode);
        }

        $this->assertSame(0, SyncRun::withoutWorkspaceScope()->count());
        $this->assertSame(1, $fixture['transport']->sendCount);
        $this->assignRoleToMembership($fixture['membership'], $role);

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'], $fixture['proposal']->flowId,
            $fixture['workspace']->id, $fixture['account']->id,
            $fixture['configuration']->id, FieldObjectType::Product,
            $fixture['product']->id,
        );
        $this->assertSame('completed', $run->status->value);
        $this->assertSame('Remote Name', $fixture['product']->fresh()->name);
        $this->assertSame(2, $fixture['transport']->sendCount);
    }

    #[Test]
    public function changed_trusted_link_after_proposal_is_not_applied_before_second_http(): void
    {
        $fixture = $this->fixture('Local Name', 'Remote Name');
        ExternalRecordLink::withoutWorkspaceScope()
            ->whereKey($fixture['link']->id)
            ->update(['external_identifier' => 'CHANGED-SKU']);

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'], $fixture['proposal']->flowId,
            $fixture['workspace']->id, $fixture['account']->id,
            $fixture['configuration']->id, FieldObjectType::Product,
            $fixture['product']->id,
        );

        $this->assertSame('Local Name', $fixture['product']->fresh()->name);
        $this->assertSame(1, $fixture['transport']->sendCount);
        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
        $this->assertSame('receive_apply_trusted_link_changed', $item->findings[0]['code']);
    }

    #[Test]
    public function configuration_change_during_fresh_remote_read_is_caught_before_local_mutation(): void
    {
        $configuration = null;
        $fixture = $this->fixture(
            'Local Name',
            'Remote Name',
            null,
            function () use (&$configuration): void {
                $configuration->configuration_revision = str_repeat('e', 64);
                $configuration->save();
            },
        );
        $configuration = $fixture['configuration'];

        $run = app(AdobeProductReceiveApplyService::class)->apply(
            $fixture['actor'], $fixture['proposal']->flowId,
            $fixture['workspace']->id, $fixture['account']->id,
            $fixture['configuration']->id, FieldObjectType::Product,
            $fixture['product']->id,
        );

        $this->assertSame('Local Name', $fixture['product']->fresh()->name);
        $item = SyncRunItem::withoutWorkspaceScope()->where('sync_run_id', $run->id)->sole();
        $this->assertSame(SyncLiveOutcome::NotApplied, $item->liveOutcome());
        $this->assertSame('receive_apply_configuration_changed', $item->findings[0]['code']);
    }

    #[Test]
    public function support_false_consumes_flow_but_creates_no_run_or_remote_apply_read(): void
    {
        $fixture = $this->fixture('Local Name', 'Remote Name');
        $this->configureAdobePaaSSyncSupportProfile([]);

        $this->expectException(SyncLiveAdmissionException::class);

        try {
            app(AdobeProductReceiveApplyService::class)->apply(
                $fixture['actor'], $fixture['proposal']->flowId,
                $fixture['workspace']->id, $fixture['account']->id,
                $fixture['configuration']->id, FieldObjectType::Product,
                $fixture['product']->id,
            );
        } finally {
            $this->assertSame('Local Name', $fixture['product']->fresh()->name);
            $this->assertSame(0, SyncRun::withoutWorkspaceScope()->count());
            $this->assertSame(1, $fixture['transport']->sendCount);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(
        string $localName,
        string $proposalRemoteName,
        ?string $applyRemoteName = null,
        ?callable $beforeApplyResponse = null,
    ): array {
        $workspace = $this->defaultWorkspace();
        $account = $this->createConnectorAccount($workspace);
        $configuration = SyncConfiguration::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'connector_account_id' => $account->id,
            'data_domain' => SyncDataDomain::Products,
            'external_context' => [],
            'enabled_operations' => [SyncSemanticOperation::Import->value],
            'operational_state' => SyncConfigurationOperationalState::Enabled,
            'configuration_revision' => hash('sha256', 'receive-apply-'.Str::uuid()),
        ]);
        $product = Product::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'SKU-RECEIVE',
            'name' => $localName,
            'is_active' => true,
        ]);
        $this->publishAuthoritativeSnapshot($account, ['name']);
        app(FieldMappingMutationService::class)->confirm(
            $account,
            $configuration->id,
            $this->productBinding('name')->id,
            'name',
        );
        $configuration = $configuration->fresh();
        $actor = $this->createStaffUser(UserRole::Manager);
        $membership = $this->grantExactWorkspacePermissions(
            $workspace, $actor, [WorkspacePermissions::RUN_SYNC_LIVE],
        );
        $link = ExternalRecordLink::withoutWorkspaceScope()->create(
            $this->merchantConfirmedParentLinkAttributes(
                $workspace,
                $account->id,
                $product,
                'SKU-RECEIVE',
                '77',
                $membership,
            ),
        );
        $transport = $this->bindTransport(function (ConnectorOutboundRequest $request, int $count) use (
            $proposalRemoteName,
            $applyRemoteName,
            $beforeApplyResponse,
        ): ConnectorHttpResult {
            if ($count === 2 && $beforeApplyResponse !== null) {
                $beforeApplyResponse();
            }
            $name = $count === 1 ? $proposalRemoteName : ($applyRemoteName ?? $proposalRemoteName);

            return new ConnectorHttpResult(200, [], json_encode([
                'id' => 77,
                'sku' => 'SKU-RECEIVE',
                'type_id' => 'simple',
                'name' => $name,
            ], JSON_THROW_ON_ERROR));
        });

        $proposal = app(AdobeProductReceiveProposalService::class)->build(
            actorUserId: $actor->id,
            workspaceId: $workspace->id,
            connectorAccountId: $account->id,
            targetType: FieldObjectType::Product,
            targetId: $product->id,
        );

        return compact(
            'workspace',
            'account',
            'configuration',
            'product',
            'actor',
            'membership',
            'link',
            'transport',
            'proposal',
        );
    }

    private function bindTransport(callable $responder): RecordingConnectorHttpTransport
    {
        $transport = new RecordingConnectorHttpTransport(
            \Closure::fromCallable($responder),
        );
        $this->app->instance(ConnectorHttpTransport::class, $transport);

        return $transport;
    }
}
