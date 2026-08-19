<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobeProductExportSemanticLayerDependencyTest extends TestCase
{
    private const FORBIDDEN_USE_PATTERNS = [
        'SyncPreviewFinding',
        'SyncPreviewFindingCode',
        'SyncPreviewOutcome',
        'SyncPreviewPlanResult',
        'SyncLiveRunJob',
        'ExternalRecordLink',
        'GuzzleHttp\\',
        'Psr\\Http\\Message',
    ];

    /**
     * @return list<string>
     */
    private function semanticLayerPhpFiles(): array
    {
        $basePath = base_path('app/Support/Connectors/AdobePaaS/Semantic');
        $files = glob($basePath.'/*.php') ?: [];

        return array_values(array_filter($files, static fn (string $path): bool => is_file($path)));
    }

    #[Test]
    public function semantic_layer_files_do_not_depend_on_preview_live_transport_or_persistence(): void
    {
        $files = $this->semanticLayerPhpFiles();

        $this->assertNotEmpty($files, 'Expected Adobe semantic layer PHP files to exist.');

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach (self::FORBIDDEN_USE_PATTERNS as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $contents,
                    "Forbidden dependency [{$pattern}] found in {$file}",
                );
            }
        }
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
