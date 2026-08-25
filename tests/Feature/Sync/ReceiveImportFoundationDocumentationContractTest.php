<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReceiveImportFoundationDocumentationContractTest extends TestCase
{
    private string $domainModelContent;

    private string $gapsContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainModelContent = File::get(base_path('docs/03-DOMAIN_MODEL.md'));
        $this->gapsContent = File::get(base_path('docs/IMPLEMENTATION_GAPS.md'));
    }

    public function test_field_mapping_remains_direction_neutral(): void
    {
        $this->assertStringContainsString(
            'FieldMapping is Direction-Neutral',
            $this->domainModelContent,
            'FieldMapping must be explicitly documented as direction-neutral.'
        );
        $this->assertStringContainsString(
            'possesses no `direction`, `authority`',
            $this->domainModelContent,
            'FieldMapping must not possess direction or authority fields.'
        );
    }

    public function test_no_silent_last_write_wins(): void
    {
        $this->assertStringContainsString(
            'no persistent field-level ownership or silent last-write-wins mechanism',
            $this->domainModelContent,
            'Must document that there is no silent last-write-wins mechanism for first manual slice.'
        );
    }

    public function test_manual_operation_time_authority(): void
    {
        $this->assertStringContainsString(
            'Manual Receive Uses Operation-Time Authority',
            $this->domainModelContent,
            'Must document manual receive uses operation-time authority.'
        );
    }

    public function test_trusted_erl_required_for_first_receive_update(): void
    {
        $this->assertStringContainsString(
            'first Receive slice operates only against an existing internal Product/Variant with an established trusted `ExternalRecordLink`',
            $this->domainModelContent,
            'Must document that trusted ERL is required for the first receive update.'
        );
    }

    public function test_no_remote_to_new_internal_product_creation_in_first_slice(): void
    {
        $this->assertStringContainsString(
            'Remote Product to new internal Product creation is out of the first Receive slice',
            $this->domainModelContent,
            'Must document that remote to new internal Product creation is out of the first Receive slice.'
        );
    }

    public function test_generic_field_writer_cannot_own_price_availability_media_relations(): void
    {
        $this->assertStringContainsString(
            'generic writer must not become a Product God Writer',
            $this->domainModelContent,
            'Must explicitly forbid the generic writer from becoming a Product God Writer.'
        );
        $this->assertStringContainsString(
            'Pricing:',
            $this->domainModelContent
        );
        $this->assertStringContainsString(
            'Availability:',
            $this->domainModelContent
        );
        $this->assertStringContainsString(
            'Relations:',
            $this->domainModelContent
        );
        $this->assertStringContainsString(
            'Media:',
            $this->domainModelContent
        );
    }

    public function test_proposal_is_not_syncrun_history_authority(): void
    {
        $this->assertStringContainsString(
            'Receive Proposal/Diff is Not SyncRun Preview',
            $this->domainModelContent,
            'Must clarify that proposal is not SyncRun preview.'
        );
        $this->assertStringContainsString(
            'not execution history, authorization, identity, or ENTITY TRUST',
            $this->domainModelContent
        );
    }

    public function test_apply_requires_fresh_revalidation(): void
    {
        $this->assertStringContainsString(
            'Apply-Time Revalidation is Mandatory',
            $this->domainModelContent,
            'Must mandate fresh revalidation before applying a receive proposal.'
        );
    }

    public function test_import_support_remains_false_until_runtime_exists(): void
    {
        $atlasContent = File::get(base_path('docs/08-CONNECTOR_SYNC_RUNTIME_ATLAS.md'));
        $this->assertStringContainsString(
            'Receive / Import runtime | CONFIRMED ABSENT',
            $atlasContent,
            'Must document that Receive / Import runtime is confirmed absent.'
        );
    }

    public function test_stage_3e_send_remains_unchanged(): void
    {
        $this->assertStringContainsString(
            'Stage 3E Send Remains Unchanged',
            $this->domainModelContent,
            'Must document that Receive-first sequencing does not reopen Stage 3E Send.'
        );
    }

    public function test_stock_rest_entity_id_receive_transport_is_validation_gate(): void
    {
        $this->assertStringContainsString(
            'entity-bound transport is a validation gate',
            $this->domainModelContent,
            'Must document that stock REST entity_id receive transport is a validation gate, not a proven assumption.'
        );
    }

    public function test_missing_governed_writer_gap_recorded(): void
    {
        $this->assertStringContainsString(
            'GAP-028',
            $this->gapsContent,
            'GAP-028 must be recorded for missing governed field-value writer.'
        );
        $this->assertStringContainsString(
            'Missing governed Product/Variant field-value writer',
            $this->gapsContent,
            'GAP-028 must specify missing governed Product/Variant field-value writer.'
        );
    }
}
