<?php

namespace Tests\Integration\MySql;

use App\Enums\AttributeDataType;
use App\Enums\AttributeScope;
use App\Enums\AttributeStatus;
use App\Enums\AttributeStorageType;
use App\Enums\FieldObjectType;
use App\Models\FieldBinding;
use App\Models\FieldDefinition;
use App\Models\Product;
use App\Models\ProductFieldValue;
use App\Models\Workspace;
use App\Services\Fields\Exceptions\FieldDefinitionArchivedException;
use App\Services\Fields\GovernedDynamicFieldValueWriter;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GAP-028A — real-MySQL concurrency proofs for the governed dynamic field-value writer.
 *
 * SQLite shares the connection state and does not expose row-level lock semantics
 * identical to MySQL/InnoDB. The writer is therefore proven on real MySQL here.
 *
 * Required proofs:
 *   1. concurrent absent-slot scalar Sets => exactly one logical row, no leaked dup
 *   2. concurrent localized uk + en Sets   => one row containing BOTH locales
 *   3. clear-last-locale concurrent with Set => valid deterministic row state
 */
class GovernedDynamicFieldValueWriterConcurrencyMySqlTest extends TestCase
{
    private const WORKER_SCRIPT = 'tests/Support/GovernedDynamicFieldValueWriterConcurrencyWorker.php';

    private const METADATA_WORKER_SCRIPT = 'tests/Support/GovernedDynamicFieldMetadataToctouWorker.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency proof.');
        }

        Artisan::call('migrate:fresh');
        $this->seed(WorkspaceSeeder::class);
    }

    #[Test]
    public function concurrent_absent_slot_scalar_sets_produce_exactly_one_logical_row(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $product = $this->makeProduct($workspace->id);
        [, $binding] = $this->makeTextDefinitionAndBinding(
            $workspace->id,
            FieldObjectType::Product,
            isLocalizable: false,
            code: 'gap028a_text_concurrency',
        );

        $ipcDir = $this->makeIpcDir('absent-slot');

        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $processes[] = $this->startWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $binding->id,
                "value-{$i}",
                null,
                $ipcDir,
            );
        }

        $results = $this->runWorkersAndCollectResults($processes, $ipcDir, 4);

        $rows = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->get();

        $this->assertCount(1, $rows, 'Expected exactly one logical row after concurrent absent-slot Sets.');

        $row = $rows->sole();
        $this->assertContains($row->value_text, ['value-0', 'value-1', 'value-2', 'value-3']);
        $this->assertNull($row->value_num);
        $this->assertNull($row->value_jsonb);

        $this->assertSame([true, true, true, true], array_column($results, 'ok'));
    }

    #[Test]
    public function concurrent_localized_uk_and_en_sets_preserve_both_locales(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $product = $this->makeProduct($workspace->id);
        [, $binding] = $this->makeTextDefinitionAndBinding(
            $workspace->id,
            FieldObjectType::Product,
            isLocalizable: true,
            code: 'gap028a_localizable_concurrency',
        );

        $ipcDir = $this->makeIpcDir('localized');

        $processes = [
            $this->startWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $binding->id,
                'Укр',
                'uk',
                $ipcDir,
            ),
            $this->startWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $binding->id,
                'En',
                'en',
                $ipcDir,
            ),
        ];

        $results = $this->runWorkersAndCollectResults($processes, $ipcDir, 2);

        $rows = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->get();

        $this->assertCount(1, $rows, 'Expected exactly one logical row after concurrent localized sets.');

        $row = $rows->sole();
        $this->assertNull($row->value_text);
        $this->assertNull($row->value_num);
        $this->assertSame(['uk' => 'Укр', 'en' => 'En'], $row->value_jsonb);
        $this->assertSame([true, true], array_column($results, 'ok'));
    }

    #[Test]
    public function clear_last_locale_concurrent_with_set_yields_valid_deterministic_state(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $product = $this->makeProduct($workspace->id);
        [, $binding] = $this->makeTextDefinitionAndBinding(
            $workspace->id,
            FieldObjectType::Product,
            isLocalizable: true,
            code: 'gap028a_clear_locale_concurrency',
        );

        // Pre-seed uk so the Set can race against clear-of-uk (last locale).
        $writer = app(GovernedDynamicFieldValueWriter::class);
        $writer->set($workspace->id, FieldObjectType::Product, $product->id, $binding->id, 'ExistingUk', 'uk');

        $ipcDir = $this->makeIpcDir('clear-last-locale');

        $processes = [
            $this->startWorker(
                'clear',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $binding->id,
                null,
                'uk',
                $ipcDir,
            ),
            $this->startWorker(
                'set',
                $workspace->id,
                FieldObjectType::Product->value,
                $product->id,
                $binding->id,
                'En',
                'en',
                $ipcDir,
            ),
        ];

        $results = $this->runWorkersAndCollectResults($processes, $ipcDir, 2);

        $rows = ProductFieldValue::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('product_id', $product->id)
            ->where('field_binding_id', $binding->id)
            ->get();

        $this->assertCount(1, $rows, 'Expected exactly one logical row after concurrent clear-last-locale + set.');

        $row = $rows->sole();
        $this->assertNull($row->value_text);
        $this->assertNull($row->value_num);
        $this->assertSame(['en' => 'En'], $row->value_jsonb);
        $this->assertSame([true, true], array_column($results, 'ok'));
    }

    #[Test]
    public function writer_revalidates_definition_under_shared_lock_before_mutation(): void
    {
        $workspace = Workspace::query()->where('is_default', true)->firstOrFail();
        $product = $this->makeProduct($workspace->id);
        [$definition, $binding] = $this->makeTextDefinitionAndBinding(
            $workspace->id,
            FieldObjectType::Product,
            isLocalizable: false,
            code: 'gap028a_metadata_toctou_definition',
        );

        $ipcDir = $this->makeIpcDir('metadata-toctou');
        $metadataProcess = new Process([
            PHP_BINARY,
            base_path(self::METADATA_WORKER_SCRIPT),
            'archive-definition-hold',
            $definition->id,
            $ipcDir,
        ], base_path());
        $metadataProcess->setTimeout(60);
        $metadataProcess->start();

        $this->waitForSpecificFile(
            $ipcDir.'/metadata_changed_uncommitted',
            'Metadata locker did not archive the definition before writer start.',
        );

        $writerProcess = $this->startWorker(
            'set',
            $workspace->id,
            FieldObjectType::Product->value,
            $product->id,
            $binding->id,
            'blocked-write',
            null,
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
        $this->assertSame(1, $writerProcess->getExitCode(), 'Writer should fail after metadata commit archives the definition.');
        $this->waitForSpecificFile($resultFile, 'Writer did not emit a result payload.');

        $payload = json_decode((string) file_get_contents($resultFile), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok'] ?? true);
        $this->assertSame(FieldDefinitionArchivedException::class, $payload['class'] ?? null);

        $this->assertDatabaseMissing('product_field_values', [
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'field_binding_id' => $binding->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeProduct(string $workspaceId): Product
    {
        return Product::query()->create([
            'workspace_id' => $workspaceId,
            'onec_guid' => (string) Str::uuid(),
            'sku' => 'GAP-028A-'.Str::random(8),
            'name' => 'GAP-028A Test Product',
            'unit' => 'шт',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: FieldDefinition, 1: FieldBinding}
     */
    private function makeTextDefinitionAndBinding(
        string $workspaceId,
        FieldObjectType $objectType,
        bool $isLocalizable,
        string $code,
    ): array {
        $definition = FieldDefinition::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'code' => $code,
            'data_type' => AttributeDataType::Text,
            'scope' => AttributeScope::PlatformLibrary,
            'localized_labels' => ['uk' => $code],
            'description' => null,
            'validation_rules' => [],
            'is_localizable' => $isLocalizable,
            'is_multi_value' => false,
            'status' => AttributeStatus::Active,
        ]);

        $binding = FieldBinding::withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'field_definition_id' => $definition->id,
            'object_type' => $objectType,
            'storage_type' => AttributeStorageType::Dynamic,
            'storage_path' => null,
            'field_group' => 'characteristics',
            'is_required' => false,
            'is_filterable' => false,
            'is_sortable' => false,
            'visibility_settings' => ['admin' => true, 'b2b' => true, 'channels' => []],
            'sort_order' => 0,
            'status' => AttributeStatus::Active,
        ]);

        return [$definition, $binding];
    }

    private function makeIpcDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/gap-028a-'.$prefix.'-'.uniqid('', true);
        if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->fail("Could not create IPC dir {$dir}");
        }

        return $dir;
    }

    private function startWorker(
        string $mode,
        string $workspaceId,
        string $targetType,
        int $targetId,
        string $fieldBindingId,
        ?string $payload,
        ?string $locale,
        string $ipcDir,
    ): Process {
        $args = [
            PHP_BINARY,
            base_path(self::WORKER_SCRIPT),
            $mode,
            $workspaceId,
            $targetType,
            (string) $targetId,
            $fieldBindingId,
            (string) $payload,
            (string) $locale,
            $ipcDir,
        ];

        $process = new Process($args, base_path());
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
