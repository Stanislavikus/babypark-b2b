<?php

namespace App\Console\Commands;

use App\Services\Connectors\ConsoleDiscoverySmokeTestPromptGateway;
use App\Services\Connectors\DiscoverySmokeTestAbortedException;
use App\Services\Connectors\DiscoverySmokeTestHarness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;

class ConnectorsDiscoverySmokeTestCommand extends Command
{
    protected $signature = 'connectors:discovery-smoke-test
                            {--actor-email= : Email of an existing active staff user}
                            {--workspace-id= : Workspace ID (defaults to the actor\'s default workspace)}
                            {--replace-credentials : Replace OAuth credentials on a matched existing account}';

    protected $description = 'Run an end-to-end schema discovery smoke test against a real Magento store (local/testing only)';

    public function handle(DiscoverySmokeTestHarness $harness): int
    {
        try {
            $harness->assertAllowedEnvironment();
        } catch (DiscoverySmokeTestAbortedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $prompts = new ConsoleDiscoverySmokeTestPromptGateway($this);

        try {
            $actorEmail = (string) $this->option('actor-email');
            $actor = $harness->resolveActor($actorEmail);
            $workspace = $harness->resolveWorkspace($actor, $this->option('workspace-id'));
            $definition = $harness->resolveAdobeDefinition();
            $harness->assertSchemaDiscoveryCapability();

            $this->info(sprintf('Actor: %s', $actor->email));
            $this->info(sprintf('Workspace: %s (%s)', $workspace->name, $workspace->id));
            $this->info(sprintf('Connector definition: %s', $definition->code));

            $baseUrl = $prompts->askBaseUrl();
            $storeCode = $prompts->askStoreCode();
            $tenantContext = $prompts->askTenantContext();

            $validated = $harness->normalizeAccountSettings($baseUrl, $storeCode, $tenantContext);

            $this->line(sprintf('Normalized base URL: %s', $validated->baseUrl));
            $this->line(sprintf('Normalized store code: %s', $validated->storeCode));
            $this->line(sprintf(
                'Normalized tenant context: %s',
                $validated->tenantContext ?? '(none)',
            ));

            $schemaSource = $harness->resolveCanonicalSchemaSource($definition);
            $this->info(sprintf('Schema source: %s (%s, %s)', $schemaSource->code, $schemaSource->source_kind->value, $schemaSource->acquisition_mode->value));

            $existingAccount = $harness->findMatchingSmokeTestAccount($workspace, $definition, $validated);

            if ($existingAccount !== null) {
                $this->info(sprintf('Matched existing account: %s (%s)', $existingAccount->name, $existingAccount->id));
            } else {
                $this->info('No matching account found — will create a new smoke-test account.');
            }

            $replaceCredentialsFlag = (bool) $this->option('replace-credentials');

            $pathResult = $harness->resolveAccountPath(
                $actor,
                $workspace,
                $definition,
                $validated,
                $existingAccount,
                $replaceCredentialsFlag,
                $prompts,
            );

            $account = $pathResult['account'];
            $this->info(sprintf('Account path: %s — using account [%s]', $pathResult['path'], $account->id));

            $harness->enableInProcessManualTrigger();

            $this->newLine();
            $this->warn('A separately-running queue worker is required before discovery dispatch.');
            $this->line('Run this command in another terminal:');
            $this->line('  '.$harness->workerCommand());
            $this->newLine();

            if (! $prompts->confirmWorkerRunning()) {
                throw new DiscoverySmokeTestAbortedException('Aborted — start the worker and re-run.');
            }

            $stability = $harness->runStabilityCheck($actor, $workspace, $account, $schemaSource, $this->output);

            $this->newLine();
            $this->info('Discovery smoke test PASSED.');
            $this->line(sprintf('Proof run 1: %s → snapshot %s → hash %s', $stability['first']['run_id'], $stability['first']['snapshot_id'], $stability['first']['canonical_hash']));
            $this->line(sprintf('Proof run 2: %s → snapshot %s → hash %s', $stability['second']['run_id'], $stability['second']['snapshot_id'], $stability['second']['canonical_hash']));
            $this->line(sprintf('Canonical hashes match: %s', $stability['first']['canonical_hash']));
            $this->newLine();
            $this->comment('What this proves: schema discovery works end-to-end on a real store.');
            $this->comment('What this does NOT prove: product import, FieldMapping, or Task 4C scope.');

            return self::SUCCESS;
        } catch (AuthorizationException $exception) {
            $this->error('Authorization failed: '.$exception->getMessage());

            return self::FAILURE;
        } catch (DiscoverySmokeTestAbortedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('An unexpected error occurred during the discovery smoke test.');
            $this->line('Exception type: '.$exception::class);

            return self::FAILURE;
        }
    }
}
