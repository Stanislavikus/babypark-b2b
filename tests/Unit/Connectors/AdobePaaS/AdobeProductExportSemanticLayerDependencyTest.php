<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductExportSemanticLayerDependencyTest extends TestCase
{
    /**
     * Historical aggregate input types temporarily allowed under Sync\Preview per Stage 3B-1.
     *
     * @var list<string>
     */
    private const ALLOWED_SYNC_PREVIEW_AGGREGATE_IMPORTS = [
        'App\Support\Sync\Preview\MappedFieldValue',
        'App\Support\Sync\Preview\ProductVariantExecutionSlice',
    ];

    /**
     * @return list<string>
     */
    private function semanticLayerPhpFiles(): array
    {
        $basePath = base_path('app/Support/Connectors/AdobePaaS/Semantic');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function extractUseStatements(string $contents): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

        $imports = [];

        foreach ($matches[1] as $rawImport) {
            $import = trim($rawImport);

            if (str_contains($import, ' as ')) {
                [$import] = explode(' as ', $import, 2);
                $import = trim($import);
            }

            $imports[] = $import;
        }

        return $imports;
    }

    private function isAllowedHistoricalPreviewAggregateImport(string $import): bool
    {
        if (in_array($import, self::ALLOWED_SYNC_PREVIEW_AGGREGATE_IMPORTS, true)) {
            return true;
        }

        return str_starts_with($import, 'App\Support\Sync\Preview\ProductExecutionAggregate');
    }

    /**
     * @return list<string>
     */
    private function collectSemanticLayerDependencyViolations(string $file, string $contents): array
    {
        $violations = [];

        foreach ($this->extractUseStatements($contents) as $import) {
            if (str_starts_with($import, 'App\Support\Sync\Preview\\')) {
                if (! $this->isAllowedHistoricalPreviewAggregateImport($import)) {
                    $violations[] = "Disallowed App\\Support\\Sync\\Preview import [{$import}] in {$file}";
                }

                continue;
            }

            if (preg_match('/\bSyncPreview[A-Za-z0-9_]*/', $import) === 1) {
                $violations[] = "SyncPreview vocabulary import [{$import}] in {$file}";
            }

            if (preg_match('/\bSyncLive[A-Za-z0-9_]*/', $import) === 1) {
                $violations[] = "SyncLive vocabulary import [{$import}] in {$file}";
            }

            if (str_starts_with($import, 'App\Support\Connectors\Transport\\')) {
                $violations[] = "Connector transport import [{$import}] in {$file}";
            }

            if (str_contains($import, 'ExternalRecordLink')) {
                $violations[] = "ExternalRecordLink import [{$import}] in {$file}";
            }

            if (str_starts_with($import, 'GuzzleHttp\\')) {
                $violations[] = "Guzzle HTTP import [{$import}] in {$file}";
            }

            if (str_starts_with($import, 'Psr\\Http\\Message\\')) {
                $violations[] = "PSR HTTP message import [{$import}] in {$file}";
            }
        }

        if (preg_match_all('/\bSyncPreview[A-Za-z0-9_]*\b/', $contents, $syncPreviewMatches) > 0) {
            foreach (array_unique($syncPreviewMatches[0]) as $match) {
                $violations[] = "SyncPreview vocabulary reference [{$match}] in {$file}";
            }
        }

        if (preg_match_all('/\bSyncLive[A-Za-z0-9_]*\b/', $contents, $syncLiveMatches) > 0) {
            foreach (array_unique($syncLiveMatches[0]) as $match) {
                $violations[] = "SyncLive vocabulary reference [{$match}] in {$file}";
            }
        }

        if (str_contains($contents, 'App\Support\Connectors\Transport\\')) {
            $violations[] = "Connector transport namespace reference in {$file}";
        }

        if (str_contains($contents, 'ExternalRecordLink')) {
            $violations[] = "ExternalRecordLink reference in {$file}";
        }

        if (str_contains($contents, 'GuzzleHttp\\')) {
            $violations[] = 'Guzzle HTTP reference in '.$file;
        }

        if (str_contains($contents, 'Psr\\Http\\Message')) {
            $violations[] = 'PSR HTTP message reference in '.$file;
        }

        return $violations;
    }

    #[Test]
    public function semantic_layer_files_do_not_depend_on_preview_live_transport_or_persistence(): void
    {
        $files = $this->semanticLayerPhpFiles();

        $this->assertNotEmpty($files, 'Expected Adobe semantic layer PHP files to exist.');

        $violations = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            $violations = array_merge(
                $violations,
                $this->collectSemanticLayerDependencyViolations($file, $contents),
            );
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function semantic_layer_scan_covers_nested_php_files_recursively(): void
    {
        $files = $this->semanticLayerPhpFiles();

        $this->assertGreaterThanOrEqual(4, count($files));

        foreach ($files as $file) {
            $this->assertStringStartsWith(
                base_path('app/Support/Connectors/AdobePaaS/Semantic'),
                $file,
            );
            $this->assertStringEndsWith('.php', $file);
        }
    }

    #[Test]
    public function content_regex_guard_catches_multiple_sync_preview_references(): void
    {
        $contents = <<<'PHP'
        <?php
        // Synthetic fixture: multiple inline references, not use imports.
        $first = SyncPreviewFindingCode::MissingName;
        $second = SyncPreviewOutcome::Blocked;
        PHP;

        $violations = $this->collectSemanticLayerDependencyViolations('synthetic-fixture.php', $contents);

        $this->assertNotEmpty($violations);
        $this->assertGreaterThanOrEqual(2, count($violations));
        $this->assertTrue(collect($violations)->contains(
            fn (string $violation): bool => str_contains($violation, 'SyncPreviewFindingCode'),
        ));
        $this->assertTrue(collect($violations)->contains(
            fn (string $violation): bool => str_contains($violation, 'SyncPreviewOutcome'),
        ));
    }

    #[Test]
    public function content_regex_guard_catches_multiple_sync_live_references(): void
    {
        $contents = <<<'PHP'
        <?php
        // Synthetic fixture: multiple inline references, not use imports.
        $job = SyncLiveRunJob::class;
        $service = SyncLiveAdmissionService::class;
        PHP;

        $violations = $this->collectSemanticLayerDependencyViolations('synthetic-fixture.php', $contents);

        $this->assertNotEmpty($violations);
        $this->assertGreaterThanOrEqual(2, count($violations));
        $this->assertTrue(collect($violations)->contains(
            fn (string $violation): bool => str_contains($violation, 'SyncLiveRunJob'),
        ));
        $this->assertTrue(collect($violations)->contains(
            fn (string $violation): bool => str_contains($violation, 'SyncLiveAdmissionService'),
        ));
    }

    #[Test]
    public function preview_planner_does_not_contain_hardcoded_blocked_finding_code_list(): void
    {
        $contents = file_get_contents(base_path('app/Support/Connectors/AdobePaaS/AdobeProductExportPreviewPlanner.php'));
        $this->assertIsString($contents);

        $this->assertStringNotContainsString('hasBlockingFinding', $contents);
        $this->assertStringNotContainsString('blockedCodes', $contents);
        $this->assertStringNotContainsString('SyncPreviewFindingCode::', $contents);
    }
}
