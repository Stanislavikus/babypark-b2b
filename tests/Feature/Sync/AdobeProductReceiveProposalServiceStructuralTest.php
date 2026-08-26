<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductReceiveProposalServiceStructuralTest extends TestCase
{
    #[Test]
    public function adobe_receive_service_remains_internal_zero_mutation_and_skips_handshake(): void
    {
        $content = File::get(base_path(
            'app/Support/Connectors/AdobePaaS/Receive/AdobeProductReceiveProposalService.php'
        ));

        $this->assertStringContainsString('AdobeProductExternalRecordLinkGuard', $content);
        $this->assertStringContainsString('AdobeSafeSyncClient', $content);
        $this->assertStringContainsString('ReceiveProposalPlanner', $content);
        $this->assertStringContainsString('ReceiveProposalFlowStore', $content);
        $this->assertStringNotContainsString('handshake(', $content);
        $this->assertStringNotContainsString('GovernedDynamicFieldValueWriter', $content);
        $this->assertStringNotContainsString('GovernedProductVariantColumnMutationService::set', $content);
        $this->assertStringNotContainsString('GovernedProductVariantColumnMutationService::clear', $content);
        $this->assertStringNotContainsString('SyncRun', $content);
        $this->assertStringNotContainsString('SyncRunItem', $content);
        $this->assertStringNotContainsString('WorkspacePermissions::RUN_SYNC_PREVIEW', $content);
        $this->assertStringNotContainsString('WorkspacePermissions::RUN_SYNC_LIVE', $content);
        $this->assertStringNotContainsString('DB::transaction', $content);
        $this->assertStringNotContainsString('lockForUpdate', $content);
        $this->assertStringNotContainsString('sharedLock', $content);
        $this->assertStringNotContainsString('->save(', $content);
    }

    #[Test]
    public function adobe_receive_service_has_no_route_controller_livewire_or_filament_entrypoint(): void
    {
        $paths = [
            base_path('routes'),
            base_path('app/Http'),
            base_path('app/Filament'),
            base_path('app/Livewire'),
        ];

        $haystack = '';

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $haystack .= File::get($file->getPathname())."\n";
            }
        }

        $this->assertStringNotContainsString('AdobeProductReceiveProposalService', $haystack);
        $this->assertStringNotContainsString('Receive proposal build', $haystack);
    }

    #[Test]
    public function field_mapping_persistence_proves_name_mapping_duplication_is_not_representable(): void
    {
        $migration = File::get(base_path('database/migrations/2026_08_12_110000_field_mappings.php'));

        $this->assertStringContainsString(
            "unique(['sync_configuration_id', 'external_field_key'], 'fm_config_external_key_unique')",
            $migration,
        );
    }
}
