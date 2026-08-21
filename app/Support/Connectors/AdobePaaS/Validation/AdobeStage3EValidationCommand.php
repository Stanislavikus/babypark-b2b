<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

use App\Models\ConnectorAccount;
use Illuminate\Console\Command;

final class AdobeStage3EValidationCommand extends Command
{
    protected $signature = 'adobe:stage3e-validate
                            {connector-account-id : Dedicated validation ConnectorAccount ID}
                            {--expect-host= : Exact expected host for ConnectorAccount base_url}
                            {--execute-real-writes : Explicit real-write acknowledgement (not authorized in Part 1)}';

    protected $description = 'Stage 3E Adobe real-target validation harness (dedicated validation environment only)';

    public function handle(AdobeStage3EValidationRunner $runner): int
    {
        try {
            AdobeStage3EValidationEnvironment::assertActive();
        } catch (AdobeStage3EValidationAbortedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $accountId = (string) $this->argument('connector-account-id');
        $expectHost = (string) $this->option('expect-host');
        $executeRealWrites = (bool) $this->option('execute-real-writes');

        $account = ConnectorAccount::query()->find($accountId);
        if ($account === null) {
            $this->error('ConnectorAccount was not found.');

            return self::FAILURE;
        }

        $guardResult = $runner->evaluateGuards($account, $expectHost, $executeRealWrites);

        if (! $guardResult->passed) {
            $this->error('Stage 3E validation guards failed:');
            foreach ($guardResult->failureCodes as $code) {
                $this->line('  - '.$code);
            }

            return self::FAILURE;
        }

        $this->info('Stage 3E validation guards passed.');
        $this->line('Real Adobe writes are not authorized in Part 1 — harness foundation only.');

        return self::SUCCESS;
    }
}
