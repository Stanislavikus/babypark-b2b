<?php

namespace Tests\Unit\CanonicalCoverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdobeProviderSemanticReviewCorrectionTest extends TestCase
{
    #[Test]
    public function current_boolean_status_mapping_survives_while_future_lifecycle_question_is_explicit(): void
    {
        $mapping = $this->adobeMappings()['status'];
        $question = $this->adobeDisagreements()['adobe_status_lifecycle'];
        $synthesis = $this->synthesis()['status'];

        $this->assertSame('transformed', $mapping['mapping_type']);
        $this->assertSame('is_active_boolean_to_adobe_enabled_disabled', $mapping['transformation']);
        $this->assertSame('DEC-010', $question['existing_decision_reference']);
        $this->assertStringContainsString('future richer platform lifecycle', $question['question']);
        $this->assertStringContainsString('current boolean enabled/disabled transformed mapping only', $synthesis['adobe_evidence']);
    }

    #[Test]
    public function ambiguous_adobe_weight_stays_deferred_and_does_not_become_a_net_or_gross_weight_mapping(): void
    {
        $synthesis = $this->synthesis();
        $mappings = $this->adobeMappings();
        $decisions = $this->adobeDecisions();

        $this->assertStringContainsString('NOT established as net_weight', $synthesis['net_weight']['adobe_evidence']);
        $this->assertStringContainsString('mapping deferred', $synthesis['net_weight']['adobe_evidence']);
        $this->assertStringContainsString('no direct Adobe gross_weight equivalence', $synthesis['gross_weight']['adobe_evidence']);
        $this->assertArrayNotHasKey('net_weight', $mappings);
        $this->assertArrayNotHasKey('gross_weight', $mappings);
        $this->assertSame('deferred', $decisions['net_weight']['decision_state']);
    }

    #[Test]
    public function adobe_country_of_manufacture_remains_related_evidence_not_a_country_of_origin_mapping(): void
    {
        $synthesis = $this->synthesis()['country_of_origin'];

        $this->assertStringContainsString('country_of_manufacture', $synthesis['adobe_evidence']);
        $this->assertStringContainsString('NOT established identity-equivalent', $synthesis['adobe_evidence']);
        $this->assertArrayNotHasKey('country_of_origin', $this->adobeMappings());
    }

    #[Test]
    public function cost_synthesis_preserves_distinct_classic_eav_and_catalog_pricing_surfaces(): void
    {
        $evidence = $this->synthesis()['cost_price']['adobe_evidence'];

        $this->assertStringContainsString('classic EAV cost', $evidence);
        $this->assertStringContainsString('Catalog Pricing cost storage', $evidence);
        $this->assertStringContainsString('distinct Adobe surfaces', $evidence);
    }

    /** @return array<string, array<string, string>> */
    private function synthesis(): array
    {
        return array_column($this->readCsv('docs/data/cross_platform_product_field_synthesis.csv'), null, 'concept_key');
    }

    /** @return array<string, array<string, string>> */
    private function adobeMappings(): array
    {
        $rows = array_filter(
            $this->readCsv('docs/data/canonical_product_field_mappings.csv'),
            fn (array $row): bool => $row['channel'] === 'adobe_commerce',
        );

        return array_column($rows, null, 'internal_code');
    }

    /** @return array<string, array<string, string>> */
    private function adobeDecisions(): array
    {
        $rows = array_filter(
            $this->readCsv('docs/data/canonical_product_field_channel_decisions.csv'),
            fn (array $row): bool => $row['channel'] === 'adobe_commerce',
        );

        return array_column($rows, null, 'internal_code');
    }

    /** @return array<string, array<string, string>> */
    private function adobeDisagreements(): array
    {
        return array_column($this->readCsv('docs/data/canonical-coverage/adobe-disagreements.csv'), null, 'question_key');
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $relativePath): array
    {
        $handle = fopen(dirname(__DIR__, 3).'/'.$relativePath, 'rb');
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
}
