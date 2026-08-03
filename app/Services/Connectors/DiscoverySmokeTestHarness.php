<?php

namespace App\Services\Connectors;

use App\Enums\ConnectorCapability;
use App\Enums\ConnectorDefinitionStatus;
use App\Enums\ConnectorDiscoveryRunStatus;
use App\Enums\ConnectorSchemaAcquisitionMode;
use App\Enums\ConnectorSchemaScope;
use App\Enums\ConnectorSchemaSourceKind;
use App\Models\ConnectorAccount;
use App\Models\ConnectorDefinition;
use App\Models\ConnectorDiscoveryRun;
use App\Models\ConnectorSchemaSnapshot;
use App\Models\ConnectorSchemaSnapshotField;
use App\Models\ConnectorSchemaSource;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Connectors\AdobePaaS\AdobePaaSSettingsInput;
use App\Support\Connectors\ConnectorAccountMutationMode;
use App\Support\Connectors\ConnectorDiscoveryDispatchDecision;
use App\Support\Connectors\ConnectorProfileRegistry;
use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use App\Support\Connectors\CredentialMutation;
use App\Support\Connectors\OAuth1\OAuth1Credentials;
use App\Support\Connectors\ValidatedConnectorAccountState;
use App\Support\Workspace\WorkspaceMembership;
use Carbon\CarbonInterface;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class DiscoverySmokeTestHarness
{
    public const AUTH_PROFILE = 'adobe_commerce_paas_oauth1_integration';

    public const DEFINITION_CODE = 'adobe_commerce';

    public const POLL_INTERVAL_SECONDS = 2;

    public const POLL_GRACE_SECONDS = 30;

    public function __construct(
        private readonly ConnectorProfileRegistry $profileRegistry,
        private readonly ConnectorAccountPersistencePort $settingsService,
        private readonly ConnectorDiscoveryDispatchPort $dispatchService,
        private readonly WorkspaceMembership $workspaceMembership,
        private readonly ConnectorSchemaSourceEndpointPathValidator $endpointPathValidator,
    ) {}

    public function assertAllowedEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DiscoverySmokeTestAbortedException(
                'This command is only available in local and testing environments.',
            );
        }
    }

    public function resolveActor(string $email): User
    {
        $normalizedEmail = trim($email);

        if ($normalizedEmail === '') {
            throw new DiscoverySmokeTestAbortedException('The --actor-email option is required.');
        }

        $actor = User::query()
            ->where('email', $normalizedEmail)
            ->where('is_active', true)
            ->whereNull('customer_id')
            ->first();

        if ($actor === null) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf('No active staff user found for email [%s].', $normalizedEmail),
            );
        }

        return $actor;
    }

    public function resolveWorkspace(User $actor, ?string $workspaceId): Workspace
    {
        if ($workspaceId !== null && $workspaceId !== '') {
            $workspace = Workspace::query()->find($workspaceId);

            if ($workspace === null) {
                throw new DiscoverySmokeTestAbortedException(
                    sprintf('Workspace [%s] was not found.', $workspaceId),
                );
            }

            if (! $this->workspaceMembership->belongs($actor, $workspace)) {
                throw new DiscoverySmokeTestAbortedException(
                    sprintf('User [%s] does not belong to workspace [%s].', $actor->email, $workspaceId),
                );
            }

            return $workspace;
        }

        $defaultWorkspace = Workspace::query()->where('is_default', true)->first();

        if ($defaultWorkspace === null) {
            throw new DiscoverySmokeTestAbortedException('No default workspace is configured.');
        }

        if (! $this->workspaceMembership->belongs($actor, $defaultWorkspace)) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf('User [%s] does not belong to the default workspace.', $actor->email),
            );
        }

        return $defaultWorkspace;
    }

    public function resolveAdobeDefinition(): ConnectorDefinition
    {
        $definition = ConnectorDefinition::query()
            ->where('code', self::DEFINITION_CODE)
            ->first();

        if ($definition === null) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf('Connector definition [%s] was not found. Run ConnectorFoundationSeeder.', self::DEFINITION_CODE),
            );
        }

        if ($definition->status !== ConnectorDefinitionStatus::Active) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf('Connector definition [%s] is not active (status: %s).', self::DEFINITION_CODE, $definition->status->value),
            );
        }

        return $definition;
    }

    public function assertSchemaDiscoveryCapability(): void
    {
        $this->profileRegistry->requireCapability(self::AUTH_PROFILE, ConnectorCapability::SchemaDiscovery);
    }

    public function normalizeAccountSettings(
        string $baseUrl,
        string $storeCode,
        ?string $tenantContext,
    ): ValidatedConnectorAccountState {
        $schema = $this->profileRegistry->resolveAccountSchema(self::AUTH_PROFILE);

        return $schema->validate(
            new AdobePaaSSettingsInput($baseUrl, $storeCode, $tenantContext),
            CredentialMutation::keep(),
            ConnectorAccountMutationMode::Update,
        );
    }

    /**
     * @return Collection<int, ConnectorSchemaSource>
     */
    public function findResolverValidSchemaSources(ConnectorDefinition $definition): Collection
    {
        return ConnectorSchemaSource::query()
            ->where('connector_definition_id', $definition->id)
            ->where('schema_scope', ConnectorSchemaScope::Account)
            ->where('source_kind', ConnectorSchemaSourceKind::AccountApi)
            ->where('acquisition_mode', ConnectorSchemaAcquisitionMode::LiveFetch)
            ->where('is_primary', true)
            ->get()
            ->filter(fn (ConnectorSchemaSource $source): bool => $this->endpointPathValidator->isValid($source->endpoint_path))
            ->values();
    }

    public function resolveCanonicalSchemaSource(ConnectorDefinition $definition): ConnectorSchemaSource
    {
        $sources = $this->findResolverValidSchemaSources($definition);
        $count = $sources->count();

        if ($count === 0) {
            throw new DiscoverySmokeTestAbortedException(
                'Canonical ConnectorFoundationSeeder schema source [live_account_attributes] appears to be missing or damaged. '
                .'Expected exactly one AccountApi/LiveFetch/Account primary source with a valid endpoint_path for adobe_commerce.',
            );
        }

        if ($count > 1) {
            $codes = $sources->pluck('code')->implode(', ');

            throw new DiscoverySmokeTestAbortedException(
                sprintf(
                    'Ambiguous schema source configuration: found %d resolver-valid AccountApi/LiveFetch/Account primary sources [%s].',
                    $count,
                    $codes,
                ),
            );
        }

        return $sources->first();
    }

    public function buildSmokeTestName(
        string $workspaceId,
        string $connectorDefinitionId,
        string $authProfile,
        string $baseUrl,
        string $storeCode,
        ?string $tenantContext,
    ): string {
        $payload = implode("\0", [
            $workspaceId,
            $connectorDefinitionId,
            $authProfile,
            $baseUrl,
            $storeCode,
            $tenantContext ?? '',
        ]);

        $hash = substr(hash('sha256', $payload), 0, 8);

        return 'Smoke Test — '.$hash;
    }

    public function findMatchingAccount(
        Workspace $workspace,
        ConnectorDefinition $definition,
        ValidatedConnectorAccountState $validated,
    ): ?ConnectorAccount {
        return ConnectorAccount::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('connector_definition_id', $definition->id)
            ->where('auth_profile', self::AUTH_PROFILE)
            ->where('base_url', $validated->baseUrl)
            ->where('store_code', $validated->storeCode)
            ->when(
                $validated->tenantContext === null,
                fn ($query) => $query->whereNull('tenant_context'),
                fn ($query) => $query->where('tenant_context', $validated->tenantContext),
            )
            ->first();
    }

    /**
     * @return array{path: string, account: ConnectorAccount}
     */
    public function resolveAccountPath(
        User $actor,
        Workspace $workspace,
        ConnectorDefinition $definition,
        ValidatedConnectorAccountState $validated,
        ?ConnectorAccount $existingAccount,
        bool $replaceCredentialsFlag,
        DiscoverySmokeTestPromptGateway $prompts,
        ?OAuth1Credentials $prefilledCredentials = null,
    ): array {
        if ($existingAccount === null) {
            Gate::forUser($actor)->authorize('create', [ConnectorAccount::class, $workspace]);

            $credentials = $prefilledCredentials ?? $prompts->askOAuth1Credentials();
            $name = $this->buildSmokeTestName(
                $workspace->id,
                $definition->id,
                self::AUTH_PROFILE,
                $validated->baseUrl,
                $validated->storeCode,
                $validated->tenantContext,
            );

            $result = $this->settingsService->create(
                $actor,
                $workspace,
                CreateConnectorAccountInput::adobePaas(
                    $definition->id,
                    $name,
                    $validated->baseUrl,
                    $validated->storeCode,
                    $validated->tenantContext,
                    CredentialMutation::replace($credentials),
                ),
            );

            $account = ConnectorAccount::withoutWorkspaceScope()->findOrFail($result->id);

            return ['path' => 'create', 'account' => $account];
        }

        if (! $existingAccount->is_enabled) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf(
                    'Matched connector account [%s] is disabled. Re-enable it manually before running this harness.',
                    $existingAccount->id,
                ),
            );
        }

        $shouldReplace = $replaceCredentialsFlag || $prompts->confirmReplaceCredentials();

        if (! $shouldReplace) {
            return ['path' => 'keep', 'account' => $existingAccount];
        }

        Gate::forUser($actor)->authorize('updateSettings', $existingAccount);
        Gate::forUser($actor)->authorize('replaceCredentials', $existingAccount);

        $credentials = $prefilledCredentials ?? $prompts->askOAuth1Credentials();

        $this->settingsService->update(
            $actor,
            $workspace,
            $existingAccount->id,
            UpdateConnectorAccountInput::adobePaas(
                $validated->baseUrl,
                $validated->storeCode,
                $validated->tenantContext,
                CredentialMutation::replace($credentials),
            ),
        );

        $existingAccount->refresh();

        return ['path' => 'replace', 'account' => $existingAccount];
    }

    public function enableInProcessManualTrigger(): void
    {
        config()->set('connectors.discovery.manual_trigger_enabled', true);
    }

    public function workerCommand(): string
    {
        return 'php artisan queue:work database_connectors --queue=connectors --timeout=900 --tries=0';
    }

    public function obtainFreshDispatch(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        ?callable $sleep = null,
    ): ConnectorDiscoveryDispatchDecision {
        $decision = $this->dispatchService->executeManual($actor, $workspace->id, $account->id);

        if (! $decision->shouldDispatch) {
            $existingRun = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($decision->discoveryRunId);
            $this->pollRunToTerminal($existingRun, $sleep);
            $decision = $this->dispatchService->executeManual($actor, $workspace->id, $account->id);
        }

        if (! $decision->shouldDispatch) {
            throw new DiscoverySmokeTestAbortedException(
                'Failed to obtain a fresh discovery dispatch after draining the active run.',
            );
        }

        return $decision;
    }

    public function computePollingDeadline(
        ConnectorDiscoveryDispatchDecision $decision,
        ConnectorDiscoveryRun $run,
    ): CarbonInterface {
        $timestamp = $decision->retryUntilTimestamp ?? $run->retry_until_at?->getTimestamp();

        if ($timestamp === null) {
            throw new DiscoverySmokeTestAbortedException('Discovery run has no retry deadline for polling.');
        }

        return Carbon::createFromTimestamp($timestamp)->addSeconds(self::POLL_GRACE_SECONDS);
    }

    public function pollRunToTerminal(
        ConnectorDiscoveryRun $run,
        ?callable $sleep = null,
        ?CarbonInterface $deadline = null,
    ): ConnectorDiscoveryRun {
        $deadline ??= $this->computePollingDeadline(
            ConnectorDiscoveryDispatchDecision::dispatch(
                $run->id,
                $run->retry_until_at?->getTimestamp() ?? now()->addHour()->getTimestamp(),
            ),
            $run,
        );

        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };

        while (now()->lt($deadline)) {
            $run->refresh();

            if ($run->isTerminal()) {
                return $run;
            }

            $sleep(self::POLL_INTERVAL_SECONDS);
        }

        throw new DiscoverySmokeTestAbortedException(
            sprintf('Discovery run [%s] did not reach a terminal state before the polling deadline.', $run->id),
        );
    }

    /**
     * @return array{
     *     run: ConnectorDiscoveryRun,
     *     snapshot: ConnectorSchemaSnapshot,
     *     sample_field_keys: list<string>,
     *     account_before: array{connection_status: string|null, last_discovery_at: string|null, last_successful_discovery_at: string|null},
     *     account_after: array{connection_status: string|null, last_discovery_at: string|null, last_successful_discovery_at: string|null},
     * }
     */
    public function validateSuccessfulRun(
        ConnectorDiscoveryRun $run,
        ConnectorSchemaSource $schemaSource,
        ConnectorAccount $accountBefore,
    ): array {
        if ($schemaSource->acquisition_mode !== ConnectorSchemaAcquisitionMode::LiveFetch) {
            throw new DiscoverySmokeTestAbortedException('Schema source is not LiveFetch.');
        }

        if ($run->status !== ConnectorDiscoveryRunStatus::Succeeded) {
            $this->throwFailedRun($run);
        }

        if ($run->execution_attempts < 1) {
            throw new DiscoverySmokeTestAbortedException('Discovery run has no execution attempts.');
        }

        if ($run->snapshot_id === null) {
            throw new DiscoverySmokeTestAbortedException('Discovery run has no snapshot.');
        }

        if (
            $run->fields_received === null
            || $run->fields_normalized === null
            || $run->fields_received < 1
            || $run->fields_normalized < 1
            || $run->fields_received !== $run->fields_normalized
        ) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf(
                    'Resolved success invariant failed: fields_received=%s, fields_normalized=%s.',
                    (string) $run->fields_received,
                    (string) $run->fields_normalized,
                ),
            );
        }

        $snapshot = ConnectorSchemaSnapshot::withoutWorkspaceScope()->findOrFail($run->snapshot_id);
        $fieldCount = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $snapshot->id)
            ->count();

        if ($fieldCount === 0) {
            throw new DiscoverySmokeTestAbortedException('Snapshot has no field rows.');
        }

        $sampleFieldKeys = ConnectorSchemaSnapshotField::withoutWorkspaceScope()
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('sort_order')
            ->limit(10)
            ->pluck('external_field_key')
            ->all();

        $accountAfter = ConnectorAccount::withoutWorkspaceScope()->findOrFail($accountBefore->id);

        $beforeDiscoveryAt = $accountBefore->last_discovery_at;
        $afterDiscoveryAt = $accountAfter->last_discovery_at;

        if ($afterDiscoveryAt === null) {
            throw new DiscoverySmokeTestAbortedException(
                'Account last_discovery_at was not set after a successful run.',
            );
        }

        if ($beforeDiscoveryAt !== null && ! $afterDiscoveryAt->gt($beforeDiscoveryAt)) {
            throw new DiscoverySmokeTestAbortedException(
                'Account last_discovery_at did not advance after a successful run.',
            );
        }

        $beforeSuccessfulAt = $accountBefore->last_successful_discovery_at;
        $afterSuccessfulAt = $accountAfter->last_successful_discovery_at;

        if ($afterSuccessfulAt === null) {
            throw new DiscoverySmokeTestAbortedException(
                'Account last_successful_discovery_at was not set after a successful run.',
            );
        }

        if ($beforeSuccessfulAt !== null && ! $afterSuccessfulAt->gt($beforeSuccessfulAt)) {
            throw new DiscoverySmokeTestAbortedException(
                'Account last_successful_discovery_at did not advance after a successful run.',
            );
        }

        return [
            'run' => $run,
            'snapshot' => $snapshot,
            'sample_field_keys' => $sampleFieldKeys,
            'account_before' => $this->accountProjectionSnapshot($accountBefore),
            'account_after' => $this->accountProjectionSnapshot($accountAfter),
        ];
    }

    /**
     * @return array{
     *     first: array{run_id: string, snapshot_id: string, canonical_hash: string},
     *     second: array{run_id: string, snapshot_id: string, canonical_hash: string},
     * }
     */
    public function runStabilityCheck(
        User $actor,
        Workspace $workspace,
        ConnectorAccount $account,
        ConnectorSchemaSource $schemaSource,
        OutputStyle $output,
        ?callable $sleep = null,
    ): array {
        $accountBaseline = ConnectorAccount::withoutWorkspaceScope()->findOrFail($account->id);
        $proofRuns = [];

        for ($iteration = 1; $iteration <= 2; $iteration++) {
            $output->writeln(sprintf('<info>Proof run %d/2 — dispatching...</info>', $iteration));

            $decision = $this->obtainFreshDispatch($actor, $workspace, $account, $sleep);
            $run = ConnectorDiscoveryRun::withoutWorkspaceScope()->findOrFail($decision->discoveryRunId);
            $deadline = $this->computePollingDeadline($decision, $run);

            $output->writeln(sprintf(
                '  Run [%s] queued; polling until %s',
                $run->id,
                $deadline->toIso8601String(),
            ));

            $terminalRun = $this->pollRunToTerminal($run, $sleep, $deadline);
            $evidence = $this->validateSuccessfulRun($terminalRun, $schemaSource, $accountBaseline);

            $output->writeln(sprintf(
                '  Run [%s] succeeded — snapshot [%s], %d fields, hash [%s]',
                $terminalRun->id,
                $evidence['snapshot']->id,
                $terminalRun->fields_received,
                $evidence['snapshot']->canonical_hash,
            ));

            $output->writeln('  Sample external_field_key values: '.implode(', ', $evidence['sample_field_keys']));
            $output->writeln('  Account projection before: '.json_encode($evidence['account_before'], JSON_THROW_ON_ERROR));
            $output->writeln('  Account projection after: '.json_encode($evidence['account_after'], JSON_THROW_ON_ERROR));

            $proofRuns[] = [
                'run_id' => $terminalRun->id,
                'snapshot_id' => $evidence['snapshot']->id,
                'canonical_hash' => (string) $evidence['snapshot']->canonical_hash,
            ];

            $accountBaseline = ConnectorAccount::withoutWorkspaceScope()->findOrFail($account->id);
        }

        if ($proofRuns[0]['run_id'] === $proofRuns[1]['run_id']) {
            throw new DiscoverySmokeTestAbortedException('Stability check produced duplicate run IDs.');
        }

        if ($proofRuns[0]['snapshot_id'] === $proofRuns[1]['snapshot_id']) {
            throw new DiscoverySmokeTestAbortedException('Stability check produced duplicate snapshot IDs.');
        }

        if ($proofRuns[0]['canonical_hash'] !== $proofRuns[1]['canonical_hash']) {
            throw new DiscoverySmokeTestAbortedException(
                sprintf(
                    'Canonical hashes differ between proof runs: [%s] vs [%s].',
                    $proofRuns[0]['canonical_hash'],
                    $proofRuns[1]['canonical_hash'],
                ),
            );
        }

        return [
            'first' => $proofRuns[0],
            'second' => $proofRuns[1],
        ];
    }

    /**
     * @return array{connection_status: string|null, last_discovery_at: string|null, last_successful_discovery_at: string|null}
     */
    public function accountProjectionSnapshot(ConnectorAccount $account): array
    {
        return [
            'connection_status' => $account->connection_status?->value,
            'last_discovery_at' => $account->last_discovery_at?->toIso8601String(),
            'last_successful_discovery_at' => $account->last_successful_discovery_at?->toIso8601String(),
        ];
    }

    private function throwFailedRun(ConnectorDiscoveryRun $run): never
    {
        throw new DiscoverySmokeTestAbortedException(
            sprintf(
                'Discovery run [%s] failed — cause=%s, actionability=%s, message_key=%s, http_status=%s.',
                $run->id,
                $run->cause_category?->value ?? 'unknown',
                $run->actionability?->value ?? 'unknown',
                $run->user_message_key ?? 'unknown',
                $run->http_status !== null ? (string) $run->http_status : 'null',
            ),
        );
    }
}
