<?php

namespace Tests\Integration\MySql;

use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Catalog\ColumnMutationResult;
use App\Services\Fields\Exceptions\FieldDefinitionArchivedException;
use App\Services\Fields\Exceptions\TargetWorkspaceMismatchException;
use Database\Seeders\FieldDefinitionSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GAP-029 MySQL-only row-lock proofs.
 *
 * SQLite does not provide equivalent InnoDB row-lock behavior, so GAP-029
 * consequence-time serialization and TOCTOU guarantees are proven on MySQL only.
 */
class GovernedProductVariantColumnMutationServiceConcurrencyMySqlTest extends TestCase
{
    private const CONCURRENCY_WORKER = 'tests/Support/GovernedProductVariantColumnMutationConcurrencyWorker.php';

    private const TOCTOU_WORKER = 'tests/Support/GovernedProductVariantColumnMutationToctouWorker.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
        $this->seed(FieldDefinitionSeeder::class);
    }

    #[Test]
    public function concurrent_name_sets_on_same_product_row_serialize_safely(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = $this->makeProduct($workspace->id);
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $ipcDir = $this->makeIpcDir('name-sets');

        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $processes[] = $this->startWriterWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $binding->id,
                "Concurrent Name {$i}",
                $ipcDir,
            );
        }

        $results = $this->runWorkersAndCollectResults($processes, $ipcDir, 4);
        $product->refresh();

        $this->assertContains($product->name, [
            'Concurrent Name 0',
            'Concurrent Name 1',
            'Concurrent Name 2',
            'Concurrent Name 3',
        ]);
        $this->assertSame('Initial description', $product->description);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(array_fill(0, 4, ColumnMutationResult::Updated), $statuses);
    }

    #[Test]
    public function concurrent_name_and_description_sets_do_not_lose_one_another(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = $this->makeProduct($workspace->id);
        $nameBinding = $this->columnBinding('name', FieldObjectType::Product);
        $descriptionBinding = $this->columnBinding('description', FieldObjectType::Product);
        $ipcDir = $this->makeIpcDir('name-description-set');

        $results = $this->runWorkersAndCollectResults([
            $this->startWriterWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $nameBinding->id,
                'Concurrent Final Name',
                $ipcDir,
            ),
            $this->startWriterWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $descriptionBinding->id,
                'Concurrent Final Description',
                $ipcDir,
            ),
        ], $ipcDir, 2);

        $product->refresh();

        $this->assertSame('Concurrent Final Name', $product->name);
        $this->assertSame('Concurrent Final Description', $product->description);
        $this->assertSame(
            [ColumnMutationResult::Updated, ColumnMutationResult::Updated],
            array_values(array_column($results, 'status')),
        );
    }

    #[Test]
    public function concurrent_name_set_and_description_clear_do_not_lose_one_another(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = $this->makeProduct($workspace->id);
        $nameBinding = $this->columnBinding('name', FieldObjectType::Product);
        $descriptionBinding = $this->columnBinding('description', FieldObjectType::Product);
        $ipcDir = $this->makeIpcDir('name-description-clear');

        $results = $this->runWorkersAndCollectResults([
            $this->startWriterWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $nameBinding->id,
                'Concurrent Cleared Description Name',
                $ipcDir,
            ),
            $this->startWriterWorker(
                'clear',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $descriptionBinding->id,
                '__unused__',
                $ipcDir,
            ),
        ], $ipcDir, 2);

        $product->refresh();

        $this->assertSame('Concurrent Cleared Description Name', $product->name);
        $this->assertNull($product->description);
        $this->assertSame(
            [ColumnMutationResult::Updated, ColumnMutationResult::Updated],
            array_values(array_column($results, 'status')),
        );
    }

    #[Test]
    public function writer_revalidates_definition_under_shared_lock_before_mutation(): void
    {
        $workspace = $this->defaultWorkspace();
        $product = $this->makeProduct($workspace->id);
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $definition = FieldDefinition::withoutWorkspaceScope()->findOrFail($binding->field_definition_id);
        $ipcDir = $this->makeIpcDir('metadata-toctou');

        $metadataProcess = new Process([
            PHP_BINARY,
            base_path(self::TOCTOU_WORKER),
            'archive-definition-hold',
            $definition->id,
            '_',
            $ipcDir,
        ], base_path());
        $metadataProcess->setTimeout(60);
        $metadataProcess->start();

        $this->waitForSpecificFile(
            $ipcDir.'/metadata_changed_uncommitted',
            'Metadata locker did not archive the definition before writer start.',
        );

        $writerProcess = $this->startWriterWorker(
            'set',
            $workspace->id,
            FieldObjectType::Product->value,
            $product->id,
            $binding->id,
            'Blocked Write',
            $ipcDir,
        );

        $this->waitForFiles($ipcDir, '*.ready', 1, 'Writer did not reach the READY barrier.');
        file_put_contents($ipcDir.'/go', '1');

        $resultFile = $ipcDir.'/'.$writerProcess->getPid().'.result';
        $blockedDeadline = microtime(true) + 1.5;

        while (microtime(true) < $blockedDeadline) {
            if (is_file($resultFile)) {
                $this->fail('Writer completed before archived metadata transaction committed.');
            }

            usleep(50_000);
        }

        $this->assertTrue($writerProcess->isRunning(), 'Writer should still be blocked on metadata shared lock.');

        file_put_contents($ipcDir.'/release_lock', '1');

        $metadataProcess->wait();
        $this->assertSame(0, $metadataProcess->getExitCode(), $metadataProcess->getErrorOutput().$metadataProcess->getOutput());
        $this->waitForSpecificFile(
            $ipcDir.'/metadata_committed',
            'Metadata locker did not commit archived definition state.',
        );

        $writerProcess->wait();
        $this->assertSame(1, $writerProcess->getExitCode(), 'Writer should fail after archived metadata commits.');
        $this->waitForSpecificFile($resultFile, 'Writer did not emit a result payload.');

        $payload = json_decode((string) file_get_contents($resultFile), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok'] ?? true);
        $this->assertSame(FieldDefinitionArchivedException::class, $payload['class'] ?? null);

        $product->refresh();
        $this->assertSame('Initial Product Name', $product->name);
    }

    #[Test]
    public function writer_revalidates_target_workspace_under_lock_before_mutation(): void
    {
        $workspace = $this->defaultWorkspace();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign Workspace',
            'is_default' => false,
        ]);
        $product = $this->makeProduct($workspace->id);
        $binding = $this->columnBinding('name', FieldObjectType::Product);
        $ipcDir = $this->makeIpcDir('target-toctou');

        $targetProcess = new Process([
            PHP_BINARY,
            base_path(self::TOCTOU_WORKER),
            'move-product-workspace-hold',
            (string) $product->id,
            $foreignWorkspace->id,
            $ipcDir,
        ], base_path());
        $targetProcess->setTimeout(60);
        $targetProcess->start();

        $this->waitForSpecificFile(
            $ipcDir.'/target_changed_uncommitted',
            'Target locker did not move the product workspace before writer start.',
        );

        $writerProcess = $this->startWriterWorker(
            'set',
            $workspace->id,
            FieldObjectType::Product->value,
            $product->id,
            $binding->id,
            'Workspace Should Fail',
            $ipcDir,
        );

        $this->waitForFiles($ipcDir, '*.ready', 1, 'Writer did not reach the READY barrier.');
        file_put_contents($ipcDir.'/go', '1');

        $resultFile = $ipcDir.'/'.$writerProcess->getPid().'.result';
        $blockedDeadline = microtime(true) + 1.5;

        while (microtime(true) < $blockedDeadline) {
            if (is_file($resultFile)) {
                $this->fail('Writer completed before target workspace transaction committed.');
            }

            usleep(50_000);
        }

        $this->assertTrue($writerProcess->isRunning(), 'Writer should still be blocked on target row lock.');

        file_put_contents($ipcDir.'/release_lock', '1');

        $targetProcess->wait();
        $this->assertSame(0, $targetProcess->getExitCode(), $targetProcess->getErrorOutput().$targetProcess->getOutput());
        $this->waitForSpecificFile(
            $ipcDir.'/target_committed',
            'Target locker did not commit moved workspace state.',
        );

        $writerProcess->wait();
        $this->assertSame(1, $writerProcess->getExitCode(), 'Writer should fail after target workspace changes at consequence time.');
        $this->waitForSpecificFile($resultFile, 'Writer did not emit a result payload.');

        $payload = json_decode((string) file_get_contents($resultFile), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok'] ?? true);
        $this->assertSame(TargetWorkspaceMismatchException::class, $payload['class'] ?? null);

        $product->refresh();
        $this->assertSame($foreignWorkspace->id, $product->workspace_id);
        $this->assertSame('Initial Product Name', $product->name);
    }

    private function defaultWorkspace(): Workspace
    {
        return Workspace::query()->where('is_default', true)->firstOrFail();
    }

    private function makeProduct(string $workspaceId): Product
    {
        return Product::query()->create([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'GAP-029A-'.Str::random(8),
            'name' => 'Initial Product Name',
            'description' => 'Initial description',
            'unit' => 'шт',
            'is_active' => true,
        ]);
    }

    private function columnBinding(string $code, FieldObjectType $objectType): FieldBinding
    {
        return FieldBinding::withoutWorkspaceScope()
            ->where('object_type', $objectType->value)
            ->whereIn('field_definition_id', FieldDefinition::withoutWorkspaceScope()
                ->where('code', $code)
                ->pluck('id'))
            ->where('storage_type', 'column')
            ->firstOrFail();
    }

    private function makeIpcDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/gap-029a-'.$prefix.'-'.uniqid('', true);
        if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->fail("Could not create IPC dir {$dir}");
        }

        return $dir;
    }

    private function startWriterWorker(
        string $mode,
        string $workspaceId,
        string $targetType,
        int $targetId,
        string $fieldBindingId,
        string $payload,
        string $ipcDir,
    ): Process {
        $process = new Process([
            PHP_BINARY,
            base_path(self::CONCURRENCY_WORKER),
            $mode,
            $workspaceId,
            $targetType,
            (string) $targetId,
            $fieldBindingId,
            $payload,
            $ipcDir,
        ], base_path());
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    /**
     * @param  list<Process>  $processes
     * @return list<array{ok: bool, status?: string, class?: string, message?: string}>
     */
    private function runWorkersAndCollectResults(array $processes, string $ipcDir, int $expectedCount): array
    {
        $this->waitForFiles($ipcDir, '*.ready', $expectedCount, 'Workers did not reach the READY barrier.');
        file_put_contents($ipcDir.'/go', '1');

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }

        $this->waitForFiles($ipcDir, '*.result', $expectedCount, 'Workers did not write all result files.');

        $files = glob($ipcDir.'/*.result') ?: [];
        $this->assertCount($expectedCount, $files, 'Expected '.$expectedCount.' result file(s).');

        $results = [];
        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($payload);
            $this->assertTrue(
                $payload['ok'] ?? false,
                'Worker reported failure: '.($payload['class'] ?? '?').': '.($payload['message'] ?? '?'),
            );

            $results[] = $payload;
        }

        return $results;
    }

    private function waitForFiles(string $ipcDir, string $pattern, int $expectedCount, string $failureMessage): void
    {
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            $matches = glob($ipcDir.'/'.$pattern) ?: [];

            if (count($matches) === $expectedCount) {
                return;
            }

            usleep(50_000);
        }

        $matches = glob($ipcDir.'/'.$pattern) ?: [];
        $this->fail($failureMessage.' Expected '.$expectedCount.', got '.count($matches).'.');
    }

    private function waitForSpecificFile(string $filePath, string $failureMessage): void
    {
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            if (is_file($filePath)) {
                return;
            }

            usleep(50_000);
        }

        $this->fail($failureMessage);
    }
}
