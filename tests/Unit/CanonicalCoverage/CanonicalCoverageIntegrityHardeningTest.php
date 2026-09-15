<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\AdobeCommerceCoverage;
use App\Support\CanonicalCoverage\AmazonCoverage;
use App\Support\CanonicalCoverage\BigCommerceCoverage;
use App\Support\CanonicalCoverage\GoogleMerchantCoverage;
use App\Support\CanonicalCoverage\ShopifyCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CanonicalCoverageIntegrityHardeningTest extends TestCase
{
    #[Test]
    public function shopify_master_semantic_reclassification_is_rejected(): void
    {
        $root = $this->temporaryCorpus($this->shopifyFiles());
        $coverage = $this->readCsv("$root/".ShopifyCoverage::COVERAGE);

        foreach ($coverage as &$row) {
            if ($row['source_file'] === ShopifyCoverage::MASTER && $row['external_key'] === 'Product.vendor') {
                $row['disposition'] = 'REUSABLE_SEMANTIC';
                $row['owner_candidate'] = 'ProductData';
                $row['representation_candidate'] = 'provider_semantic_field';
                break;
            }
        }
        unset($row);
        $this->writeCsv("$root/".ShopifyCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify master contract mismatch');
        (new ShopifyCoverage)->validate($root);
    }

    #[Test]
    public function shopify_alias_must_point_to_its_resolved_target(): void
    {
        $root = $this->temporaryCorpus($this->shopifyFiles());
        $coverage = $this->readCsv("$root/".ShopifyCoverage::COVERAGE);
        $taxonomyTarget = null;

        foreach ($coverage as $row) {
            if ($row['source_file'] === ShopifyCoverage::TAXONOMY) {
                $taxonomyTarget = $row['coverage_id'];
                break;
            }
        }
        $this->assertNotNull($taxonomyTarget);

        foreach ($coverage as &$row) {
            if ($row['source_file'] === ShopifyCoverage::ALIASES) {
                $row['alias_of_coverage_id'] = $taxonomyTarget;
                break;
            }
        }
        unset($row);
        $this->writeCsv("$root/".ShopifyCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify alias target mismatch');
        (new ShopifyCoverage)->validate($root);
    }

    #[Test]
    public function all_five_provider_validators_reject_mutated_immutable_manifest_provenance(): void
    {
        $root = $this->temporaryCorpus($this->allProviderFiles());
        $originalManifest = $this->readCsv("$root/".BigCommerceCoverage::MANIFEST);
        $cases = [
            ['bigcommerce', BigCommerceCoverage::SOURCE, new BigCommerceCoverage],
            ['adobe_commerce', AdobeCommerceCoverage::MASTER, new AdobeCommerceCoverage],
            ['google_merchant', GoogleMerchantCoverage::ATTRIBUTES, new GoogleMerchantCoverage],
            ['amazon', AmazonCoverage::META, new AmazonCoverage],
            ['shopify', ShopifyCoverage::MASTER, new ShopifyCoverage],
        ];

        foreach ($cases as [$platform, $sourceFile, $validator]) {
            $manifest = $originalManifest;
            foreach ($manifest as &$row) {
                if ($row['platform'] === $platform && $row['source_file'] === $sourceFile) {
                    $row['repository_commit'] = str_repeat('0', 40);
                    $row['schema_version_basis'] = 'corrupted-basis';
                    $row['captured_at'] = '2099-01-01';
                    break;
                }
            }
            unset($row);
            $this->writeCsv("$root/".BigCommerceCoverage::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);

            try {
                $validator->validate($root);
                $this->fail("$platform validator accepted mutated immutable manifest provenance");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('manifest', strtolower($exception->getMessage()), $platform);
            }
        }
    }

    #[Test]
    public function every_manifest_row_reproduces_exact_source_bytes_from_its_recorded_commit(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = $this->readCsv($root.'/'.BigCommerceCoverage::MANIFEST);

        foreach ($manifest as $row) {
            $commit = $row['repository_commit'];
            $commitCheck = proc_open(
                ['git', '-C', $root, 'cat-file', '-e', $commit.'^{commit}'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $commitPipes,
            );
            stream_get_contents($commitPipes[1]);
            stream_get_contents($commitPipes[2]);
            fclose($commitPipes[1]);
            fclose($commitPipes[2]);
            if (proc_close($commitCheck) !== 0) {
                $fetch = proc_open(
                    ['git', '-C', $root, 'fetch', '--no-tags', 'origin', $commit],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $fetchPipes,
                );
                stream_get_contents($fetchPipes[1]);
                $fetchError = stream_get_contents($fetchPipes[2]);
                fclose($fetchPipes[1]);
                fclose($fetchPipes[2]);
                $this->assertSame(0, proc_close($fetch), $commit.' '.$fetchError);
            }

            $spec = $commit.':'.$row['source_file'];
            $process = proc_open(
                ['git', '-C', $root, 'show', $spec],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process, $spec);
            $bytes = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, $spec.' '.$stderr);
            $this->assertSame($row['file_sha256'], hash('sha256', $bytes), $spec);
        }
    }

    /** @return list<string> */
    private function shopifyFiles(): array
    {
        return [
            BigCommerceCoverage::MANIFEST,
            ShopifyCoverage::MASTER,
            ShopifyCoverage::STRUCTURED,
            ShopifyCoverage::ALIASES,
            ShopifyCoverage::TAXONOMY,
            ShopifyCoverage::FRESHNESS,
            ShopifyCoverage::VERSIONS,
            ShopifyCoverage::CLUSTERS,
            ShopifyCoverage::SOURCES,
            ShopifyCoverage::STANDARD_METAFIELDS,
            ShopifyCoverage::COVERAGE,
            ShopifyCoverage::CONCEPTS,
            ShopifyCoverage::DISAGREEMENTS,
        ];
    }

    /** @return list<string> */
    private function allProviderFiles(): array
    {
        return array_values(array_unique(array_merge($this->shopifyFiles(), [
            BigCommerceCoverage::SOURCE,
            BigCommerceCoverage::COVERAGE,
            BigCommerceCoverage::CONCEPTS,
            BigCommerceCoverage::DISAGREEMENTS,
            AdobeCommerceCoverage::MASTER,
            AdobeCommerceCoverage::STRUCTURED,
            AdobeCommerceCoverage::ALIASES,
            AdobeCommerceCoverage::CLUSTERS,
            AdobeCommerceCoverage::SOURCES,
            AdobeCommerceCoverage::COVERAGE,
            AdobeCommerceCoverage::CONCEPTS,
            AdobeCommerceCoverage::DISAGREEMENTS,
            GoogleMerchantCoverage::ATTRIBUTES,
            GoogleMerchantCoverage::PRODUCT_INPUT,
            GoogleMerchantCoverage::COVERAGE,
            GoogleMerchantCoverage::CONCEPTS,
            GoogleMerchantCoverage::DISAGREEMENTS,
            AmazonCoverage::META,
            AmazonCoverage::LUGGAGE,
            AmazonCoverage::COVERAGE,
            AmazonCoverage::CONCEPTS,
            AmazonCoverage::DISAGREEMENTS,
        ])));
    }

    /** @param list<string> $files */
    private function temporaryCorpus(array $files): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir().'/canonical-coverage-hardening-'.bin2hex(random_bytes(8));

        foreach ($files as $file) {
            $target = "$root/$file";
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            copy("$source/$file", $target);
        }

        return $root;
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values !== [null]) {
                $rows[] = array_combine($header, $values);
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<array<string, string>> $rows */
    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);
    }
}
