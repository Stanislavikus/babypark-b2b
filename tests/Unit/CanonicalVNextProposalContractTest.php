<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CanonicalVNextProposalContractTest extends TestCase
{
    #[Test]
    public function proposal_accounts_for_current_registry_and_missing_union_exactly_once(): void
    {
        $root = dirname(__DIR__, 2);
        $current = $this->readCsv($root.'/docs/data/canonical_product_fields.csv');
        $proposal = $this->readCsv($root.'/docs/data/five_provider_canonical_vnext_decisions.csv');

        $currentRows = array_values(array_filter(
            $proposal,
            fn (array $row): bool => $row['registry_state'] === 'CURRENT_65',
        ));
        $missingRows = array_values(array_filter(
            $proposal,
            fn (array $row): bool => $row['registry_state'] === 'MISSING_UNION',
        ));

        $this->assertCount(65, $currentRows);
        $this->assertCount(31, $missingRows);
        $this->assertCount(96, $proposal);

        $currentCodes = array_column($current, 'internal_code');
        $proposalCurrentCodes = array_column($currentRows, 'concept_key');
        sort($currentCodes);
        sort($proposalCurrentCodes);

        $this->assertSame($currentCodes, $proposalCurrentCodes);
        $this->assertCount(
            count($proposal),
            array_unique(array_column($proposal, 'concept_key')),
            'Every vNext concept_key must be unique.',
        );

        foreach ($proposal as $row) {
            foreach (['lead_decision', 'confidence', 'evidence_summary', 'materialization_gate', 'lead_note'] as $column) {
                $this->assertNotSame('', trim($row[$column]), "{$row['concept_key']} missing {$column}");
            }
        }
    }

    #[Test]
    public function strong_additions_and_promotions_stay_explicitly_bounded(): void
    {
        $rows = $this->proposalByConcept();

        $this->assertSame('ADD_PLATFORM_LIBRARY', $rows['slug']['lead_decision']);
        $this->assertSame('ADD_PLATFORM_LIBRARY_BINDING_REVIEW', $rows['size_system']['lead_decision']);

        foreach (['shipping_weight', 'shipping_dimensions', 'harmonized_system_code', 'unit_pricing_measure'] as $concept) {
            $this->assertSame('ADD_DOMAIN_CANONICAL', $rows[$concept]['lead_decision'], $concept);
        }

        foreach (['condition', 'manufacturer', 'model'] as $concept) {
            $this->assertSame('CURRENT_65', $rows[$concept]['registry_state'], $concept);
            $this->assertSame('PROMOTE_ACTIVE_VERIFIED', $rows[$concept]['lead_decision'], $concept);
        }

        $this->assertSame(
            'PROMOTE_SEMANTIC_CONFIDENCE_BINDING_REVIEW',
            $rows['material']['lead_decision'],
        );
        $this->assertSame('BINDING_ARBITRATION_BEFORE_SEED', $rows['material']['materialization_gate']);
        $this->assertSame('ACCEPT_CONCEPT_DEFER_OWNER', $rows['max_order_quantity']['lead_decision']);
        $this->assertSame('OWNER_AND_SCOPE_DECISION', $rows['max_order_quantity']['materialization_gate']);
    }

    #[Test]
    public function false_friends_cannot_sneak_in_as_active_duplicate_fields(): void
    {
        $rows = $this->proposalByConcept();

        $this->assertSame('REJECT_AMBIGUOUS_OVERLAP', $rows['product_weight']['lead_decision']);
        $this->assertSame('MERGE_AS_PROVIDER_REPRESENTATION', $rows['package_weight']['lead_decision']);
        $this->assertSame('MERGE_AS_PROVIDER_REPRESENTATION', $rows['package_dimensions']['lead_decision']);

        foreach (['name', 'url', 'mpn'] as $concept) {
            $this->assertSame('CURRENT_65', $rows[$concept]['registry_state'], $concept);
        }
        foreach (['net_weight', 'gross_weight'] as $concept) {
            $this->assertSame('KEEP_STRICT_SEMANTICS', $rows[$concept]['lead_decision'], $concept);
        }
        $this->assertSame('KEEP_CLARIFY_SEMANTICS', $rows['brand']['lead_decision']);
        $this->assertSame('KEEP_CLARIFY_SEMANTICS', $rows['gtin']['lead_decision']);
        $this->assertSame('KEEP_CLARIFY_SEMANTICS', $rows['status']['lead_decision']);
    }

    /** @return array<string, array<string, string>> */
    private function proposalByConcept(): array
    {
        return array_column(
            $this->readCsv(dirname(__DIR__, 2).'/docs/data/five_provider_canonical_vnext_decisions.csv'),
            null,
            'concept_key',
        );
    }

    /** @return list<array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle, $path);
        $header = fgetcsv($handle, escape: '\\');
        $this->assertIsArray($header);

        $rows = [];
        while (($data = fgetcsv($handle, escape: '\\')) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }
            $this->assertCount(count($header), $data, $path);
            $rows[] = array_combine($header, $data);
        }
        fclose($handle);

        return $rows;
    }
}
