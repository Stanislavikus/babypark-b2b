<?php

namespace Tests\Feature\Sync;

use App\Enums\FieldObjectType;
use App\Enums\ReceiveDiffState;
use App\Enums\ReceiveDomainRoute;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Sync\Receive\ReceiveProposalEntry;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveProposalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function proposal_captures_configuration_revision_and_target_correlation_as_evidence_only(): void
    {
        $issuedAt = new DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $proposal = new ReceiveProposal(
            workspaceId: 'workspace-1',
            connectorAccountId: 'account-1',
            syncConfigurationId: 'config-1',
            configurationRevision: str_repeat('a', 64),
            targetType: FieldObjectType::ProductVariant,
            targetId: '42',
            trustedExternalLinkEvidenceId: 'erl-evidence-1',
            entries: [
                new ReceiveProposalEntry(
                    fieldBindingId: 'binding-1',
                    objectType: FieldObjectType::ProductVariant,
                    domainRoute: ReceiveDomainRoute::DynamicField,
                    diffState: ReceiveDiffState::Differs,
                    localValuePresent: true,
                    localCanonicalValue: 'local',
                    remoteValuePresent: true,
                    remoteCanonicalValue: 'remote',
                    explicitClear: false,
                ),
            ],
            issuedAt: $issuedAt,
        );

        $this->assertSame(str_repeat('a', 64), $proposal->configurationRevision);
        $this->assertSame(FieldObjectType::ProductVariant, $proposal->targetType);
        $this->assertSame('42', $proposal->targetId);
        $this->assertSame('erl-evidence-1', $proposal->trustedExternalLinkEvidenceId);
        $this->assertSame($issuedAt, $proposal->issuedAt);
    }

    #[Test]
    public function proposal_has_no_sync_run_persistence_and_causes_no_database_mutation(): void
    {
        $beforeRunCount = SyncRun::withoutWorkspaceScope()->count();
        $beforeItemCount = SyncRunItem::withoutWorkspaceScope()->count();

        $proposal = new ReceiveProposal(
            workspaceId: 'workspace-1',
            connectorAccountId: 'account-1',
            syncConfigurationId: 'config-1',
            configurationRevision: str_repeat('b', 64),
            targetType: FieldObjectType::Product,
            targetId: '11',
            trustedExternalLinkEvidenceId: 'erl-evidence-2',
            entries: [],
            issuedAt: new DateTimeImmutable,
        );

        $this->assertSame([], $proposal->entries);
        $this->assertSame($beforeRunCount, SyncRun::withoutWorkspaceScope()->count());
        $this->assertSame($beforeItemCount, SyncRunItem::withoutWorkspaceScope()->count());
    }
}
