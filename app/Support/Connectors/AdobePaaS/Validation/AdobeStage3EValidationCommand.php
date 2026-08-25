<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use Illuminate\Console\Command;

final class AdobeStage3EValidationCommand extends Command
{
    protected $signature = 'adobe:stage3e-validate
        {connector-account-id : Validation ConnectorAccount ID}
        {product-variant-id : Local ProductVariant ID resolved through a trusted ERL}
        {--expect-host= : Exact expected host for ConnectorAccount base_url}
        {--execute-real-writes : Explicit real-write acknowledgement}
        {--ack-write-sku= : Exact B2BVAL-* SKU acknowledgement}
        {--restore-after-known-applied : Perform one bounded restore after a known-applied baseline write}
        {--simulate-transport-loss-after-write : Throw a local transport exception after one armed Safe Sync PUT delegate completes}';

    protected $description = 'Stage 3E disposable validation harness (validation-only control plane over Safe Sync primitives).';

    public function handle(AdobeStage3EValidationRunner $runner): int
    {
        $result = $runner->run(new AdobeStage3EValidationRunInput(
            connectorAccountId: (string) $this->argument('connector-account-id'),
            productVariantId: (string) $this->argument('product-variant-id'),
            expectHost: (string) $this->option('expect-host'),
            executeRealWrites: (bool) $this->option('execute-real-writes'),
            ackWriteSku: trim((string) $this->option('ack-write-sku')),
            restoreAfterKnownApplied: (bool) $this->option('restore-after-known-applied'),
            simulateTransportLossAfterWrite: (bool) $this->option('simulate-transport-loss-after-write'),
        ));

        $this->line('Stage 3E validation result: '.$result->outcome->value);
        foreach ($result->failureCodes as $failureCode) {
            $this->line('  failure: '.$failureCode);
        }
        foreach ($result->messages as $message) {
            $this->line('  note: '.$message);
        }
        $this->line('Evidence: '.$result->artifactPath);

        return $result->exitCode();
    }
}
