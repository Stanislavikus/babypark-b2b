<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveProposalFoundationStructuralTest extends TestCase
{
    #[Test]
    public function receive_core_has_no_adobe_or_magento_dependencies(): void
    {
        $content = $this->receiveCoreContents();

        $this->assertStringNotContainsString('AdobeSafeSyncClient', $content);
        $this->assertStringNotContainsString('AdobeSafeSyncVerifiedProduct', $content);
        $this->assertStringNotContainsString('Magento', $content);
        $this->assertStringNotContainsString('ConnectorHttpTransport', $content);
    }

    #[Test]
    public function receive_core_invokes_no_product_writers_or_sync_run_persistence(): void
    {
        $content = $this->receiveCoreContents();

        $this->assertStringNotContainsString('GovernedDynamicFieldValueWriter', $content);
        $this->assertStringNotContainsString('GovernedProductVariantColumnMutationService', $content);
        $this->assertStringNotContainsString('SyncRun', $content);
        $this->assertStringNotContainsString('SyncRunItem', $content);
        $this->assertStringNotContainsString('->save(', $content);
    }

    private function receiveCoreContents(): string
    {
        $paths = [
            'app/Enums/ReceiveDiffState.php',
            'app/Enums/ReceiveDomainRoute.php',
            'app/Services/Sync/Receive/ReceiveProposalPlanner.php',
            'app/Services/Sync/Receive/ReceiveProposalFlowStore.php',
            'app/Support/Sync/Receive/ReceiveFieldCandidate.php',
            'app/Support/Sync/Receive/ReceiveProposal.php',
            'app/Support/Sync/Receive/ReceiveProposalEntry.php',
            'app/Support/Sync/Receive/ReceiveProposalFlowBinding.php',
        ];

        return collect($paths)
            ->map(fn (string $path): string => File::get(base_path($path)))
            ->implode("\n");
    }
}
