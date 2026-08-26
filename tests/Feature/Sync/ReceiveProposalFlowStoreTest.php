<?php

namespace Tests\Feature\Sync;

use App\Enums\FieldObjectType;
use App\Enums\ReceiveDiffState;
use App\Enums\ReceiveDomainRoute;
use App\Services\Sync\Receive\ReceiveProposalFlowStore;
use App\Support\Sync\Receive\ReceiveProposal;
use App\Support\Sync\Receive\ReceiveProposalEntry;
use App\Support\Sync\Receive\ReceiveProposalFlowBinding;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReceiveProposalFlowStoreTest extends TestCase
{
    private ReceiveProposalFlowStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file']);
        Cache::flush();

        $this->store = app(ReceiveProposalFlowStore::class);
    }

    #[Test]
    public function issue_returns_opaque_unpredictable_id_without_exposing_payload_contents(): void
    {
        $flowIdA = $this->store->issue($this->binding(), $this->proposal());
        $flowIdB = $this->store->issue($this->binding(targetId: '43'), $this->proposal(targetId: '43'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $flowIdA);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $flowIdB);
        $this->assertNotSame($flowIdA, $flowIdB);
        $this->assertStringNotContainsString('workspace-1', $flowIdA);
        $this->assertStringNotContainsString('binding-1', $flowIdA);
        $this->assertStringNotContainsString('local-value', $flowIdA);
    }

    #[Test]
    public function correct_binding_can_consume_once_and_replay_loses(): void
    {
        $flowId = $this->store->issue($this->binding(), $this->proposal());

        $consumed = $this->store->consume($flowId, $this->binding());

        $this->assertInstanceOf(ReceiveProposal::class, $consumed);
        $this->assertSame('workspace-1', $consumed->workspaceId);
        $this->assertNull($this->store->consume($flowId, $this->binding()));
    }

    #[Test]
    public function mismatched_binding_fails_closed_for_actor_workspace_account_configuration_and_target(): void
    {
        $flowId = $this->store->issue($this->binding(), $this->proposal());

        $mismatches = [
            $this->binding(actorUserId: '999'),
            $this->binding(workspaceId: 'workspace-2'),
            $this->binding(connectorAccountId: 'account-2'),
            $this->binding(syncConfigurationId: 'config-2'),
            $this->binding(targetType: FieldObjectType::ProductVariant),
            $this->binding(targetId: '999'),
        ];

        foreach ($mismatches as $binding) {
            $this->assertNull($this->store->consume($flowId, $binding));
        }

        $this->assertInstanceOf(ReceiveProposal::class, $this->store->consume($flowId, $this->binding()));
    }

    #[Test]
    public function expired_or_missing_flow_returns_invalid(): void
    {
        $flowId = $this->store->issue($this->binding(), $this->proposal(), ttlSeconds: 1);

        sleep(2);

        $this->assertNull($this->store->consume($flowId, $this->binding()));
        $this->assertNull($this->store->consume(str_repeat('f', 64), $this->binding()));
    }

    #[Test]
    public function discard_invalidates_flow(): void
    {
        $flowId = $this->store->issue($this->binding(), $this->proposal());

        $this->assertTrue($this->store->discard($flowId, $this->binding()));
        $this->assertNull($this->store->consume($flowId, $this->binding()));
    }

    #[Test]
    public function concurrent_consume_has_exactly_one_winner(): void
    {
        $flowId = $this->store->issue($this->binding(), $this->proposal());
        $ipcDir = sys_get_temp_dir().'/receive-proposal-flow-'.uniqid('', true);
        mkdir($ipcDir);

        $workerScript = base_path('tests/Support/ReceiveProposalFlowStoreConsumeWorker.php');
        $env = array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'CACHE_STORE' => 'file',
        ]);

        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([
                PHP_BINARY,
                $workerScript,
                $flowId,
                '501',
                'workspace-1',
                'account-1',
                'config-1',
                FieldObjectType::Product->value,
                '42',
                $ipcDir,
            ], base_path(), $env);
            $process->setTimeout(60);
            $process->start();
            $processes[] = [
                'pid' => $process->getPid(),
                'process' => $process,
            ];
        }

        $this->waitForFiles($ipcDir, '*.ready', 4);
        file_put_contents($ipcDir.'/go', '1');

        $results = [];
        foreach ($processes as $entry) {
            /** @var Process $process */
            $process = $entry['process'];
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

            $resultPath = $ipcDir.'/'.$entry['pid'].'.result';
            $this->assertFileExists($resultPath);
            $results[] = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
        }

        $this->assertSame(1, array_sum(array_map(
            static fn (array $result): int => $result['consumed'] ? 1 : 0,
            $results,
        )));
    }

    private function proposal(string $targetId = '42'): ReceiveProposal
    {
        return new ReceiveProposal(
            workspaceId: 'workspace-1',
            connectorAccountId: 'account-1',
            syncConfigurationId: 'config-1',
            configurationRevision: str_repeat('c', 64),
            targetType: FieldObjectType::Product,
            targetId: $targetId,
            trustedExternalLinkEvidenceId: 'erl-evidence-1',
            entries: [
                new ReceiveProposalEntry(
                    fieldBindingId: 'binding-1',
                    objectType: FieldObjectType::Product,
                    domainRoute: ReceiveDomainRoute::DynamicField,
                    diffState: ReceiveDiffState::Differs,
                    localValuePresent: true,
                    localCanonicalValue: 'local-value',
                    remoteValuePresent: true,
                    remoteCanonicalValue: 'remote-value',
                    explicitClear: false,
                ),
            ],
            issuedAt: new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
        );
    }

    private function binding(
        string $actorUserId = '501',
        string $workspaceId = 'workspace-1',
        string $connectorAccountId = 'account-1',
        string $syncConfigurationId = 'config-1',
        FieldObjectType $targetType = FieldObjectType::Product,
        string $targetId = '42',
    ): ReceiveProposalFlowBinding {
        return new ReceiveProposalFlowBinding(
            actorUserId: $actorUserId,
            workspaceId: $workspaceId,
            connectorAccountId: $connectorAccountId,
            syncConfigurationId: $syncConfigurationId,
            targetType: $targetType,
            targetId: $targetId,
        );
    }

    private function waitForFiles(string $directory, string $pattern, int $expectedCount, int $seconds = 30): void
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $files = glob($directory.'/'.$pattern) ?: [];

            if (count($files) >= $expectedCount) {
                return;
            }

            usleep(100_000);
        }

        $this->fail("Timed out waiting for {$expectedCount} IPC files matching {$pattern}.");
    }
}
