<?php

namespace App\Support\CanonicalCoverage;

use RuntimeException;

final class BigCommerceCoverage
{
    public const BASE_COMMIT = '5be3ec03ec915e6831ad86777d5284aa083be206';

    public const SOURCE = 'docs/data/bigcommerce_v3_product_capability_inventory.csv';

    public const COVERAGE = 'docs/data/canonical-coverage/bigcommerce.csv';

    public const CONCEPTS = 'docs/data/canonical-coverage/bigcommerce-concepts.csv';

    public const MANIFEST = 'docs/data/canonical_vocabulary_source_manifest.csv';

    public const SEPARATOR = "\x1f";

    public const COVERAGE_HEADER = ['coverage_id', 'snapshot_id', 'platform', 'source_file', 'source_row_ordinal', 'source_row_sha256', 'source_schema_version', 'source_surface', 'source_object_family', 'external_key', 'structured_path', 'source_context_key', 'parent_coverage_id', 'semantic_atom_key', 'concept_key', 'disposition', 'owner_candidate', 'representation_candidate', 'entity_level', 'value_shape', 'read_semantics', 'write_semantics', 'applicability_key', 'alias_of_coverage_id', 'evidence_reference', 'decision_reference', 'review_status', 'review_note'];

    public const CONCEPT_HEADER = ['concept_key', 'preferred_name', 'semantic_definition', 'entity_level', 'value_type', 'cardinality', 'structured_shape_ref', 'source_of_truth_kind', 'read_contract', 'write_contract', 'applicability_kind', 'owner_candidate', 'representation_candidate', 'localization_kind', 'vocabulary_kind', 'concept_status', 'evidence_platforms', 'evidence_coverage_count', 'alias_concept_keys', 'broader_concept_key', 'conflicts_with_concept_keys', 'decision_reference', 'confidence', 'review_status', 'review_note'];

    public const DISPOSITIONS = ['REUSABLE_SEMANTIC', 'CATEGORY_ATTRIBUTE', 'DOMAIN_CAPABILITY', 'CHANNEL_SEMANTIC', 'EXTERNAL_IDENTITY', 'APPLICABILITY_METADATA', 'STRUCTURE_MEMBER', 'DERIVED_PROJECTION', 'TRANSPORT_MECHANIC', 'ALIAS_REPRESENTATION', 'OUT_OF_SCOPE', 'DEFER_DECISION'];

    /** @return array<string, mixed> */
    public function generate(string $root): array
    {
        $sourcePath = "$root/".self::SOURCE;
        [$header, $rows] = $this->readCsv($sourcePath);
        $fileHash = hash_file('sha256', $sourcePath);
        $snapshot = 'bigcommerce-v1-'.substr($fileHash, 0, 16);
        $raw = file_get_contents($sourcePath);
        $headerBytes = strstr($raw, "\n", true)."\n";
        $manifest = [[
            'snapshot_id' => $snapshot, 'repository_commit' => self::BASE_COMMIT,
            'platform' => 'bigcommerce', 'source_file' => self::SOURCE,
            'file_sha256' => $fileHash, 'header_sha256' => hash('sha256', $headerBytes),
            'row_count' => (string) count($rows), 'schema_version_basis' => 'OpenAPI 3.1.0 inventory snapshot',
            'captured_at' => '2026-09-07',
        ]];

        $coverage = [];
        foreach ($rows as $index => $row) {
            $ordinal = $index + 1;
            $rowHash = hash('sha256', json_encode(array_values($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $concept = $this->conceptKey($row);
            [$disposition, $owner, $representation, $note] = $this->classify($row);
            $coverage[] = array_combine(self::COVERAGE_HEADER, [
                hash('sha256', implode(self::SEPARATOR, [$snapshot, self::SOURCE, (string) $ordinal, $rowHash])),
                $snapshot, 'bigcommerce', self::SOURCE, (string) $ordinal, $rowHash,
                $row['openapi_version'], 'Admin Catalog OpenAPI', $row['object_family'], $row['external_field'],
                'not_applicable', $row['object_family'], 'not_applicable',
                'bigcommerce:atom:'.$row['object_family'].':'.$this->slug($row['external_field']), $concept,
                $disposition, $owner, $representation, $this->entityLevel($row), $this->valueShape($row['type_or_ref']),
                $this->readContract($row['read_semantics']), $this->writeContract($row['write_semantics']),
                'bigcommerce:'.$row['object_family'], 'not_applicable', $row['source_url'].'#'.$row['schema_evidence'],
                $disposition === 'DEFER_DECISION' ? 'queue:'.$this->slug($row['external_field']) : 'not_applicable',
                'PROVIDER_VERIFIED', $note,
            ]);
        }

        $concepts = $this->buildConcepts($coverage, $rows);
        $this->writeCsv("$root/".self::MANIFEST, array_keys($manifest[0]), $manifest);
        $this->writeCsv("$root/".self::COVERAGE, self::COVERAGE_HEADER, $coverage);
        $this->writeCsv("$root/".self::CONCEPTS, self::CONCEPT_HEADER, $concepts);

        return $this->metrics($coverage, $concepts, count($rows));
    }

    /** @return array<string, mixed> */
    public function validate(string $root): array
    {
        [$manifestHeader, $manifest] = $this->readCsv("$root/".self::MANIFEST);
        [$coverageHeader, $coverage] = $this->readCsv("$root/".self::COVERAGE);
        [$conceptHeader, $concepts] = $this->readCsv("$root/".self::CONCEPTS);
        $errors = [];
        if ($manifestHeader !== ['snapshot_id', 'repository_commit', 'platform', 'source_file', 'file_sha256', 'header_sha256', 'row_count', 'schema_version_basis', 'captured_at'] || count($manifest) !== 1) {
            $errors[] = 'manifest contract mismatch';
        }
        if ($coverageHeader !== self::COVERAGE_HEADER) {
            $errors[] = 'coverage header mismatch';
        }
        if ($conceptHeader !== self::CONCEPT_HEADER) {
            $errors[] = 'concept header mismatch';
        }
        [$sourceHeader, $sourceRows] = $this->readCsv("$root/".self::SOURCE);
        $sourceBytes = file_get_contents("$root/".self::SOURCE);
        if ($manifest[0]['file_sha256'] !== hash('sha256', $sourceBytes)) {
            $errors[] = 'source file hash mismatch';
        }
        if ($manifest[0]['header_sha256'] !== hash('sha256', strstr($sourceBytes, "\n", true)."\n")) {
            $errors[] = 'source header hash mismatch';
        }
        if ((int) $manifest[0]['row_count'] !== count($sourceRows) || count($coverage) !== count($sourceRows)) {
            $errors[] = 'physical coverage mismatch';
        }
        $seen = [];
        $conceptIndex = array_column($concepts, null, 'concept_key');
        foreach ($coverage as $i => $row) {
            $ordinal = $i + 1;
            $source = $sourceRows[$i] ?? [];
            $rowHash = hash('sha256', json_encode(array_values($source), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $expected = hash('sha256', implode(self::SEPARATOR, [$manifest[0]['snapshot_id'], self::SOURCE, (string) $ordinal, $rowHash]));
            if ($row['source_row_ordinal'] !== (string) $ordinal || $row['source_row_sha256'] !== $rowHash || $row['coverage_id'] !== $expected) {
                $errors[] = "row $ordinal provenance mismatch";
            }
            $physical = $row['source_file'].'#'.$row['source_row_ordinal'];
            if (isset($seen[$physical])) {
                $errors[] = "duplicate physical coverage $physical";
            }
            $seen[$physical] = true;
            if (! in_array($row['disposition'], self::DISPOSITIONS, true)) {
                $errors[] = "invalid disposition at row $ordinal";
            }
            if ($row['concept_key'] === '' || ! isset($conceptIndex[$row['concept_key']])) {
                $errors[] = "missing concept at row $ordinal";
            }
            if ($row['source_file'] !== self::SOURCE) {
                $errors[] = "undeclared source at row $ordinal";
            }
        }
        foreach ($concepts as $concept) {
            $evidence = array_values(array_filter($coverage, fn ($row) => $row['concept_key'] === $concept['concept_key']));
            if ($evidence === []) {
                $errors[] = 'orphan concept '.$concept['concept_key'];
            }
            if ((int) $concept['evidence_coverage_count'] !== count($evidence) || $concept['evidence_platforms'] !== 'bigcommerce') {
                $errors[] = 'derived evidence mismatch '.$concept['concept_key'];
            }
            foreach ($this->list($concept['conflicts_with_concept_keys']) as $conflict) {
                if (! isset($conceptIndex[$conflict])) {
                    $errors[] = "invalid conflict $conflict";
                }
                if (in_array($conflict, $this->list($concept['alias_concept_keys']), true)) {
                    $errors[] = "conflict/equivalence contradiction $conflict";
                }
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $this->metrics($coverage, $concepts, count($sourceRows));
    }

    private function conceptKey(array $row): string
    {
        $shared = ['bin_picking_number', 'cost_price', 'depth', 'fixed_cost_shipping_price', 'gtin', 'height', 'inventory_level', 'inventory_warning_level', 'is_free_shipping', 'mpn', 'price', 'retail_price', 'sale_price', 'sku', 'upc', 'weight', 'width'];
        if (in_array($row['object_family'], ['product', 'product_variant'], true) && in_array($row['external_field'], $shared, true)) {
            return 'bigcommerce:'.$this->slug($row['external_field']);
        }

        return 'bigcommerce:'.$row['object_family'].':'.$this->slug($row['external_field']);
    }

    private function classify(array $row): array
    {
        $class = $row['classification'];
        $map = [
            'semantic_product_field' => ['REUSABLE_SEMANTIC', 'ProductData', 'provider_field'],
            'pricing_domain' => ['DOMAIN_CAPABILITY', 'Pricing', 'domain_value'],
            'price_list_domain' => ['DOMAIN_CAPABILITY', 'Pricing', 'capability_endpoint'],
            'inventory_availability_domain' => ['DOMAIN_CAPABILITY', 'Availability', 'domain_value'],
            'inventory_domain' => ['DOMAIN_CAPABILITY', 'Inventory', 'capability_endpoint'],
            'media_domain' => ['DOMAIN_CAPABILITY', 'Media', 'domain_value'],
            'relation_or_structured_capability' => ['DOMAIN_CAPABILITY', 'ProductComposition', 'structured_capability'],
            'option_modifier_schema' => ['DOMAIN_CAPABILITY', $row['object_family'] === 'product_modifier' ? 'OrderCustomization' : 'VariantComposition', 'provider_schema_field'],
            'channel_or_storefront_context' => ['CHANNEL_SEMANTIC', 'Connector', 'channel_context'],
            'external_system_identity' => ['EXTERNAL_IDENTITY', 'Connector', 'external_record_identity'],
            'computed_projection' => ['DERIVED_PROJECTION', 'Pricing', 'read_projection'],
            'derived_or_external_metadata' => ['DERIVED_PROJECTION', 'Connector', 'read_or_platform_projection'],
        ];
        [$disposition, $owner, $representation] = $map[$class];
        $note = 'Provider-local classification from typed inventory evidence; no cross-platform equivalence asserted.';
        if (in_array($row['external_field'], ['price', 'retail_price', 'cost_price', 'sale_price', 'weight', 'depth', 'height', 'width'], true) && $row['object_family'] === 'product_variant') {
            $note = 'Same provider semantic as Product field; Variant binding is nullable and inherits/falls back according to source description.';
        }
        if ($row['object_family'] === 'product_modifier') {
            $note = 'Order-time customization capability; intentionally distinct from variant dimension/product option.';
        }
        if ($row['object_family'] === 'product_option') {
            $note = 'Variant option schema; intentionally distinct from order-time modifier/customization.';
        }
        if (in_array($row['external_field'], ['map_price', 'retail_price', 'price', 'sale_price'], true)) {
            $note .= ' Pricing purpose is preserved; MAP, reference retail, base, and sale prices are not merged.';
        }

        return [$disposition, $owner, $representation, $note];
    }

    private function buildConcepts(array $coverage, array $sourceRows): array
    {
        $sourceByOrdinal = [];
        foreach ($sourceRows as $i => $r) {
            $sourceByOrdinal[$i + 1] = $r;
        }
        $groups = [];
        foreach ($coverage as $row) {
            $groups[$row['concept_key']][] = $row;
        }
        $result = [];
        foreach ($groups as $key => $rows) {
            $first = $rows[0];
            $source = $sourceByOrdinal[(int) $first['source_row_ordinal']];
            $entities = array_values(array_unique(array_column($rows, 'entity_level')));
            $conflicts = $this->conflicts($key, array_keys($groups));
            $result[] = array_combine(self::CONCEPT_HEADER, [
                $key, str_replace('_', ' ', $source['external_field']),
                $source['description'] !== '' ? preg_replace('/\s+/', ' ', $source['description']) : "BigCommerce {$source['object_family']} {$source['external_field']} capability.",
                count($entities) > 1 ? 'ProductAndProductVariant' : $entities[0], $this->valueShape($source['type_or_ref']), str_starts_with($source['type_or_ref'], 'array') ? 'many' : 'optional_one',
                str_starts_with($source['type_or_ref'], 'array') || str_starts_with($source['type_or_ref'], 'ref:') ? $source['type_or_ref'] : 'not_applicable',
                in_array($first['disposition'], ['DERIVED_PROJECTION', 'EXTERNAL_IDENTITY'], true) ? 'provider_owned' : 'merchant_or_domain',
                implode('|', array_unique(array_column($rows, 'read_semantics'))), implode('|', array_unique(array_column($rows, 'write_semantics'))),
                'provider_context', $first['owner_candidate'], $first['representation_candidate'], 'not_localizable',
                str_starts_with($source['type_or_ref'], 'ref:') ? 'provider_controlled' : 'open_or_typed', $this->conceptStatus($first['disposition']),
                'bigcommerce', (string) count($rows), 'not_applicable', 'not_applicable', $conflicts === [] ? 'not_applicable' : implode('|', $conflicts),
                'provider-pass:no-final-freeze', 'supported', 'PROVIDER_VERIFIED', count($rows) > 1 ? 'Shared provider concept with distinct entity binding and explicit per-row contracts.' : 'Provider-local concept; cross-platform merge is deferred to the later campaign pass.',
            ]);
        }
        ksort($groups);
        usort($result, fn ($a, $b) => $a['concept_key'] <=> $b['concept_key']);

        return $result;
    }

    private function conflicts(string $key, array $known): array
    {
        $sets = [
            ['bigcommerce:product:map_price', 'bigcommerce:retail_price', 'bigcommerce:price', 'bigcommerce:sale_price'],
            ['bigcommerce:product:type', 'bigcommerce:product_option:type', 'bigcommerce:product_modifier:type'],
            ['bigcommerce:product_option:option_values', 'bigcommerce:product_modifier:option_values', 'bigcommerce:product_variant:option_values'],
            ['bigcommerce:product:custom_url', 'bigcommerce:product:open_graph_title'],
        ];
        foreach ($sets as $set) {
            if (in_array($key, $set, true)) {
                return array_values(array_intersect(array_diff($set, [$key]), $known));
            }
        }

        return [];
    }

    private function conceptStatus(string $disposition): string
    {
        return match ($disposition) {
            'REUSABLE_SEMANTIC' => 'reusable_candidate', 'DOMAIN_CAPABILITY' => 'domain', 'CHANNEL_SEMANTIC' => 'channel', 'DERIVED_PROJECTION' => 'projection', 'EXTERNAL_IDENTITY' => 'governance', default => 'deferred'
        };
    }

    private function entityLevel(array $row): string
    {
        return match ($row['object_family']) {
            'product' => 'Product', 'product_variant' => 'ProductVariant', 'product_option' => 'ProductOption', 'product_modifier' => 'OrderModifier', 'price_list' => 'PriceList', 'inventory' => 'Inventory', default => 'ProviderContext'
        };
    }

    private function valueShape(string $type): string
    {
        return str_starts_with($type, 'array') ? 'list' : (str_starts_with($type, 'ref:') ? 'object_or_controlled' : (str_contains($type, 'capability_endpoint') ? 'capability' : (str_contains($type, 'boolean') ? 'boolean' : (str_contains($type, 'integer') ? 'integer' : (str_contains($type, 'number') ? 'decimal' : 'string')))));
    }

    private function readContract(string $value): string
    {
        return match ($value) {
            'READ' => 'readable', 'none' => 'unsupported', default => 'not_proven'
        };
    }

    private function writeContract(string $value): string
    {
        return match ($value) {
            'WRITE' => 'writable', 'none' => 'unsupported', default => 'specialized_or_read_only'
        };
    }

    private function slug(string $value): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value)), '_');
    }

    private function list(string $value): array
    {
        return $value === '' || $value === 'not_applicable' ? [] : explode('|', $value);
    }

    private function metrics(array $coverage, array $concepts, int $denominator): array
    {
        $nonTerminal = array_filter($coverage, fn ($r) => ! in_array($r['disposition'], ['OUT_OF_SCOPE'], true));
        $terminal = array_filter($coverage, fn ($r) => in_array($r['disposition'], ['OUT_OF_SCOPE'], true));

        return [
            'manifest_rows' => $denominator, 'coverage_rows' => count($coverage), 'concepts' => count($concepts),
            'coverage_ratio' => (float) (count($coverage) / $denominator), 'classification_ratio' => (float) (count(array_filter($coverage, fn ($r) => $r['disposition'] !== '')) / count($coverage)),
            'concept_link_ratio' => (float) (count(array_filter($nonTerminal, fn ($r) => $r['concept_key'] !== '')) / max(1, count($nonTerminal))),
            'terminal_rationale_ratio' => $terminal === [] ? 1.0 : count(array_filter($terminal, fn ($r) => $r['decision_reference'] !== 'not_applicable')) / count($terminal),
            'silent_drop_count' => $denominator - count($coverage), 'dispositions' => array_count_values(array_column($coverage, 'disposition')),
        ];
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read $path");
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            } $rows[] = array_combine($header, $values);
        }
        fclose($handle);

        return [$header, $rows];
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $handle = fopen($path, 'wb');
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        } fclose($handle);
    }
}
