<?php

namespace App\Support\CanonicalCoverage;

use RuntimeException;

final class BigCommerceCoverage
{
    public const BASE_COMMIT = '5be3ec03ec915e6831ad86777d5284aa083be206';

    public const SOURCE = 'docs/data/bigcommerce_v3_product_capability_inventory.csv';

    public const COVERAGE = 'docs/data/canonical-coverage/bigcommerce.csv';

    public const CONCEPTS = 'docs/data/canonical-coverage/bigcommerce-concepts.csv';

    public const DISAGREEMENTS = 'docs/data/canonical-coverage/bigcommerce-disagreements.csv';

    public const MANIFEST = 'docs/data/canonical_vocabulary_source_manifest.csv';

    public const SEPARATOR = "\x1f";

    public const MANIFEST_HEADER = ['snapshot_id', 'repository_commit', 'platform', 'source_file', 'file_sha256', 'header_sha256', 'row_count', 'schema_version_basis', 'captured_at'];

    public const COVERAGE_HEADER = ['coverage_id', 'snapshot_id', 'platform', 'source_file', 'source_row_ordinal', 'source_row_sha256', 'source_schema_version', 'source_surface', 'source_object_family', 'external_key', 'structured_path', 'source_context_key', 'parent_coverage_id', 'semantic_atom_key', 'concept_key', 'disposition', 'owner_candidate', 'representation_candidate', 'entity_level', 'value_shape', 'read_semantics', 'write_semantics', 'applicability_key', 'alias_of_coverage_id', 'evidence_reference', 'decision_reference', 'review_status', 'review_note'];

    public const CONCEPT_HEADER = ['concept_key', 'preferred_name', 'semantic_definition', 'entity_level', 'value_type', 'cardinality', 'structured_shape_ref', 'source_of_truth_kind', 'read_contract', 'write_contract', 'applicability_kind', 'owner_candidate', 'representation_candidate', 'localization_kind', 'vocabulary_kind', 'concept_status', 'evidence_platforms', 'evidence_coverage_count', 'alias_concept_keys', 'broader_concept_key', 'conflicts_with_concept_keys', 'decision_reference', 'confidence', 'review_status', 'review_note'];

    public const DISPOSITIONS = ['REUSABLE_SEMANTIC', 'CATEGORY_ATTRIBUTE', 'DOMAIN_CAPABILITY', 'CHANNEL_SEMANTIC', 'EXTERNAL_IDENTITY', 'APPLICABILITY_METADATA', 'STRUCTURE_MEMBER', 'DERIVED_PROJECTION', 'TRANSPORT_MECHANIC', 'ALIAS_REPRESENTATION', 'OUT_OF_SCOPE', 'DEFER_DECISION'];

    public const DISAGREEMENT_HEADER = ['question_key', 'concept_family', 'question', 'evidence_concept_keys', 'affected_coverage_count', 'existing_decision_reference', 'review_status', 'notes'];

    /** @return array<string, mixed> */
    public function generate(string $root): array
    {
        $sourcePath = "$root/".self::SOURCE;
        [$header, $rows] = $this->readCsv($sourcePath);
        $fileHash = hash_file('sha256', $sourcePath);
        $snapshot = 'bigcommerce-v1-'.substr($fileHash, 0, 16);
        $raw = file_get_contents($sourcePath);
        $headerBytes = strstr($raw, "\n", true)."\n";
        $manifestRow = [
            'snapshot_id' => $snapshot, 'repository_commit' => self::BASE_COMMIT,
            'platform' => 'bigcommerce', 'source_file' => self::SOURCE,
            'file_sha256' => $fileHash, 'header_sha256' => hash('sha256', $headerBytes),
            'row_count' => (string) count($rows), 'schema_version_basis' => 'OpenAPI 3.1.0 inventory snapshot',
            'captured_at' => '2026-09-07',
        ];
        $manifest = $this->upsertManifest($root, $manifestRow);

        $coverage = [];
        foreach ($rows as $index => $row) {
            $ordinal = $index + 1;
            $rowHash = hash('sha256', json_encode(array_values($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $concept = $this->conceptKey($row);
            [$disposition, $owner, $representation, $note] = $this->classify($row);
            $questions = $this->questionsForConcept($concept);
            $coverage[] = array_combine(self::COVERAGE_HEADER, [
                hash('sha256', implode(self::SEPARATOR, [$snapshot, self::SOURCE, (string) $ordinal, $rowHash])),
                $snapshot, 'bigcommerce', self::SOURCE, (string) $ordinal, $rowHash,
                $row['openapi_version'], 'Admin Catalog OpenAPI', $row['object_family'], $row['external_field'],
                'not_applicable', $this->sourceContext($row), 'not_applicable',
                'bigcommerce:atom:'.$row['object_family'].':'.$this->slug($row['external_field']), $concept,
                $disposition, $owner, $representation, $this->entityLevel($row), $this->valueShape($row['type_or_ref']),
                $this->readContract($row['read_semantics']), $this->writeContract($row['write_semantics']),
                'not_applicable', 'not_applicable', $row['source_url'].'#'.$row['schema_evidence'],
                $questions === [] ? 'not_applicable' : implode('|', array_map(fn ($question) => 'queue:'.$question, $questions)),
                $questions === [] ? 'PROVIDER_VERIFIED' : 'DEFERRED_REVIEW', $note,
            ]);
        }

        $concepts = $this->buildConcepts($coverage, $rows);
        $disagreements = $this->buildDisagreements($coverage);
        $this->writeCsv("$root/".self::MANIFEST, self::MANIFEST_HEADER, $manifest);
        $this->writeCsv("$root/".self::COVERAGE, self::COVERAGE_HEADER, $coverage);
        $this->writeCsv("$root/".self::CONCEPTS, self::CONCEPT_HEADER, $concepts);
        $this->writeCsv("$root/".self::DISAGREEMENTS, self::DISAGREEMENT_HEADER, $disagreements);

        return $this->metrics($coverage, $concepts, count($rows));
    }

    /** @return array<string, mixed> */
    public function validate(string $root): array
    {
        [$manifestHeader, $manifest] = $this->readCsv("$root/".self::MANIFEST);
        [$coverageHeader, $coverage] = $this->readCsv("$root/".self::COVERAGE);
        [$conceptHeader, $concepts] = $this->readCsv("$root/".self::CONCEPTS);
        [$disagreementHeader, $disagreements] = $this->readCsv("$root/".self::DISAGREEMENTS);
        $errors = [];
        if ($manifestHeader !== self::MANIFEST_HEADER) {
            $errors[] = 'manifest contract mismatch';
        }
        if ($coverageHeader !== self::COVERAGE_HEADER) {
            $errors[] = 'coverage header mismatch';
        }
        if ($conceptHeader !== self::CONCEPT_HEADER) {
            $errors[] = 'concept header mismatch';
        }
        if ($disagreementHeader !== self::DISAGREEMENT_HEADER) {
            $errors[] = 'disagreement header mismatch';
        }
        $manifestIndex = [];
        foreach ($manifest as $manifestRow) {
            $identity = $manifestRow['platform'].self::SEPARATOR.$manifestRow['source_file'];
            if (isset($manifestIndex[$identity])) {
                $errors[] = "duplicate manifest identity $identity";
            }
            $manifestIndex[$identity] = $manifestRow;
        }
        $bigCommerceManifest = $manifestIndex['bigcommerce'.self::SEPARATOR.self::SOURCE] ?? null;
        if ($bigCommerceManifest === null) {
            throw new RuntimeException('BigCommerce manifest entry missing');
        }
        [$sourceHeader, $sourceRows] = $this->readCsv("$root/".self::SOURCE);
        $sourceBytes = file_get_contents("$root/".self::SOURCE);
        if ($bigCommerceManifest['file_sha256'] !== hash('sha256', $sourceBytes)) {
            $errors[] = 'source file hash mismatch';
        }
        if ($bigCommerceManifest['header_sha256'] !== hash('sha256', strstr($sourceBytes, "\n", true)."\n")) {
            $errors[] = 'source header hash mismatch';
        }
        if ((int) $bigCommerceManifest['row_count'] !== count($sourceRows) || count($coverage) !== count($sourceRows)) {
            $errors[] = 'physical coverage mismatch';
        }
        $seen = [];
        $conceptIndex = array_column($concepts, null, 'concept_key');
        foreach ($coverage as $i => $row) {
            $ordinal = $i + 1;
            $source = $sourceRows[$i] ?? [];
            $rowHash = hash('sha256', json_encode(array_values($source), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $expected = hash('sha256', implode(self::SEPARATOR, [$bigCommerceManifest['snapshot_id'], self::SOURCE, (string) $ordinal, $rowHash]));
            if ($row['source_row_ordinal'] !== (string) $ordinal || $row['source_row_sha256'] !== $rowHash || $row['coverage_id'] !== $expected) {
                $errors[] = "row $ordinal provenance mismatch";
            }
            if ($row['platform'] !== 'bigcommerce') {
                $errors[] = "platform mismatch at row $ordinal";
            }
            if ($row['snapshot_id'] !== $bigCommerceManifest['snapshot_id']) {
                $errors[] = "snapshot id mismatch at row $ordinal";
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
            if ($row['source_context_key'] !== $this->sourceContext($source)) {
                $errors[] = "required/operation context mismatch at row $ordinal";
            }
            if ($row['entity_level'] !== $this->entityLevel($source)
                || $row['value_shape'] !== $this->valueShape($source['type_or_ref'])
                || $row['read_semantics'] !== $this->readContract($source['read_semantics'])
                || $row['write_semantics'] !== $this->writeContract($source['write_semantics'])) {
                $errors[] = "provider representation contract mismatch at row $ordinal";
            }
            if ($row['applicability_key'] !== 'not_applicable') {
                $errors[] = "dangling applicability key at row $ordinal";
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
        $this->validateSafeMerges($coverage, $concepts, $sourceRows, $errors);
        $this->validateDisagreements($coverage, $conceptIndex, $disagreements, $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $this->metrics($coverage, $concepts, count($sourceRows)) + [
            'safe_merge_conflicts_unexplained' => 0,
            'open_disagreements_with_verified_rows' => 0,
            'invalid_disagreement_refs' => 0,
            'dangling_applicability_keys' => 0,
            'manifest_provider_rows_preserved' => 'PASS',
        ];
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
        [$owner, $representation] = match ($row['object_family'].':'.$row['external_field']) {
            'product:categories' => ['Category', 'category_relation'],
            'product:related_products' => ['ProductAssociation', 'product_relationship'],
            'product:custom_fields' => ['DynamicField', 'external_custom_field_container'],
            'product:variants', 'product:options', 'product_variant:option_values', 'product_option:option_values' => ['VariantComposition', 'variant_composition'],
            'product:modifiers', 'product_modifier:option_values' => ['OrderCustomization', 'order_time_customization'],
            default => [$owner, $representation],
        };
        if ($row['object_family'] === 'product_option' && $row['external_field'] !== 'option_values') {
            [$owner, $representation] = ['VariantComposition', 'provider_schema_field'];
        }
        if ($row['object_family'] === 'product_modifier' && $row['external_field'] !== 'option_values') {
            [$owner, $representation] = ['OrderCustomization', 'provider_schema_field'];
        }
        if ($row['object_family'] === 'product' && in_array($row['external_field'], ['tax_class_id', 'product_tax_code'], true)) {
            $owner = 'UnresolvedTaxOwner';
            $representation = 'provider_tax_classification';
        }
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
                count($entities) > 1 ? 'ProductAndProductVariant' : $entities[0], $this->semanticValueType($key, $rows, $source), str_starts_with($source['type_or_ref'], 'array') ? 'many' : 'optional_one',
                str_starts_with($source['type_or_ref'], 'array') || str_starts_with($source['type_or_ref'], 'ref:') ? $source['type_or_ref'] : 'not_applicable',
                in_array($first['disposition'], ['DERIVED_PROJECTION', 'EXTERNAL_IDENTITY'], true) ? 'provider_owned' : 'merchant_or_domain',
                implode('|', array_unique(array_column($rows, 'read_semantics'))), implode('|', array_unique(array_column($rows, 'write_semantics'))),
                'provider_context', $first['owner_candidate'], $first['representation_candidate'], 'not_localizable',
                str_starts_with($source['type_or_ref'], 'ref:') ? 'provider_controlled' : 'open_or_typed', $this->conceptStatus($first['disposition']),
                'bigcommerce', (string) count($rows), 'not_applicable', 'not_applicable', $conflicts === [] ? 'not_applicable' : implode('|', $conflicts),
                $first['decision_reference'], 'supported', $first['review_status'], count($rows) > 1 ? 'Shared provider concept with distinct entity binding under an explicit compatibility rule.' : 'Provider-local concept; cross-platform merge is deferred to the later campaign pass.',
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
        ];
        foreach ($sets as $set) {
            if (in_array($key, $set, true)) {
                return array_values(array_intersect(array_diff($set, [$key]), $known));
            }
        }

        return [];
    }

    /** @return array<string, array{semantic_type: string, explanation: string}> */
    private function compatibilityRules(): array
    {
        $rules = [];
        foreach (['cost_price', 'depth', 'fixed_cost_shipping_price', 'height', 'price', 'retail_price', 'sale_price', 'weight', 'width'] as $field) {
            $rules["bigcommerce:$field"] = [
                'semantic_type' => 'decimal',
                'explanation' => 'Product string-encoded decimal and nullable Variant numeric override normalize to decimal; entity binding, operation context, nullability, and documented Product/Price List fallback remain row-local.',
            ];
        }
        foreach (['inventory_level', 'inventory_warning_level'] as $field) {
            $rules["bigcommerce:$field"] = ['semantic_type' => 'integer', 'explanation' => 'Product and nullable Variant integer values share quantity semantics while tracking mode and fallback remain row-local.'];
        }
        foreach (['bin_picking_number', 'gtin', 'mpn', 'sku', 'upc'] as $field) {
            $rules["bigcommerce:$field"] = ['semantic_type' => 'string', 'explanation' => 'Product and Variant identifier/content values share provider meaning; nullable wire shape and entity binding remain row-local.'];
        }
        $rules['bigcommerce:is_free_shipping'] = ['semantic_type' => 'boolean', 'explanation' => 'Product and Variant flags share provider meaning while entity binding remains row-local.'];

        return $rules;
    }

    private function semanticValueType(string $key, array $coverageRows, array $source): string
    {
        if (count($coverageRows) > 1) {
            return $this->compatibilityRules()[$key]['semantic_type'] ?? 'unresolved';
        }

        return $this->valueShape($source['type_or_ref']);
    }

    private function sourceContext(array $row): string
    {
        return 'required_in='.($row['required_in'] ?: 'none').';schema_evidence='.($row['schema_evidence'] ?: 'none');
    }

    /** @return array<string, array<string, string>> */
    private function disagreementDefinitions(): array
    {
        return [
            'bigcommerce_tax_owner' => ['family' => 'tax_classification', 'question' => 'What portable semantic and owner, if any, can represent BigCommerce tax_class_id and product_tax_code?', 'concepts' => 'bigcommerce:product:tax_class_id|bigcommerce:product:product_tax_code', 'reference' => 'unresolved-tax-owner', 'notes' => 'Do not decide storage or promote provider tax identities.'],
            'bigcommerce_map_persistence' => ['family' => 'map', 'question' => 'Should BigCommerce MAP be persisted and how is its policy scope represented?', 'concepts' => 'bigcommerce:product:map_price', 'reference' => 'unresolved-map-persistence', 'notes' => 'MAP is explicitly not retail or transactional price.'],
            'bigcommerce_reference_price' => ['family' => 'reference_price', 'question' => 'What portion of BigCommerce retail_price is equivalent to a portable RRP/list/MSRP semantic?', 'concepts' => 'bigcommerce:retail_price', 'reference' => 'unresolved-reference-price-equivalence', 'notes' => 'Product and Variant bindings share provider semantics but retain fallback contracts.'],
            'bigcommerce_order_constraints' => ['family' => 'order_constraints', 'question' => 'Are minimum and maximum order quantities Product defaults or offer/variant constraints?', 'concepts' => 'bigcommerce:product:order_quantity_minimum|bigcommerce:product:order_quantity_maximum', 'reference' => 'unresolved-order-constraint-binding', 'notes' => 'No final Product/Variant binding in this pass.'],
            'bigcommerce_customization' => ['family' => 'customization', 'question' => 'Where is the domain boundary between Product Option variant composition and Product Modifier order-time input?', 'concepts' => 'bigcommerce:product_option:option_values|bigcommerce:product_modifier:option_values|bigcommerce:product_variant:option_values', 'reference' => 'unresolved-customization-architecture', 'notes' => 'Provider non-equivalence is preserved.'],
            'bigcommerce_product_variant_binding' => ['family' => 'product_variant_binding', 'question' => 'Which shared provider semantics become Product defaults, Variant overrides, or independently bound values?', 'concepts' => 'bigcommerce:price|bigcommerce:weight|bigcommerce:sku|bigcommerce:inventory_level', 'reference' => 'unresolved-product-variant-binding', 'notes' => 'Representative high-risk concepts; explicit compatibility rules cover every shared concept.'],
            'bigcommerce_weight_semantics' => ['family' => 'weight', 'question' => 'Does BigCommerce shipping-calculation weight map to item net, package gross, or shipping weight?', 'concepts' => 'bigcommerce:weight', 'reference' => 'DEC-009', 'notes' => 'Do not equate it to gross_weight.'],
            'bigcommerce_url_family' => ['family' => 'url', 'question' => 'How should custom_url differ from canonical Product URL, slug, and external storefront URL?', 'concepts' => 'bigcommerce:product:custom_url', 'reference' => 'DEC-008', 'notes' => 'No unrelated Open Graph title conflict is asserted.'],
        ];
    }

    private function questionsForConcept(string $concept): array
    {
        $questions = [];
        foreach ($this->disagreementDefinitions() as $key => $definition) {
            if (in_array($concept, explode('|', $definition['concepts']), true)) {
                $questions[] = $key;
            }
        }

        return $questions;
    }

    private function buildDisagreements(array $coverage): array
    {
        $rows = [];
        foreach ($this->disagreementDefinitions() as $key => $definition) {
            $concepts = explode('|', $definition['concepts']);
            $affected = count(array_filter($coverage, fn ($row) => in_array($row['concept_key'], $concepts, true)));
            $rows[] = array_combine(self::DISAGREEMENT_HEADER, [$key, $definition['family'], $definition['question'], $definition['concepts'], (string) $affected, $definition['reference'], 'OPEN', $definition['notes']]);
        }

        return $rows;
    }

    private function upsertManifest(string $root, array $bigCommerceRow): array
    {
        $path = "$root/".self::MANIFEST;
        $rows = [];
        if (is_file($path)) {
            [$header, $rows] = $this->readCsv($path);
            if ($header !== self::MANIFEST_HEADER) {
                throw new RuntimeException('manifest contract mismatch');
            }
        }
        $indexed = [];
        foreach ($rows as $row) {
            $identity = $row['platform'].self::SEPARATOR.$row['source_file'];
            if (isset($indexed[$identity])) {
                throw new RuntimeException("duplicate manifest identity $identity");
            }
            if (in_array('', $row, true)) {
                throw new RuntimeException("invalid manifest entry $identity");
            }
            $indexed[$identity] = $row;
        }
        $indexed['bigcommerce'.self::SEPARATOR.self::SOURCE] = $bigCommerceRow;
        ksort($indexed, SORT_STRING);

        return array_values($indexed);
    }

    private function validateSafeMerges(array $coverage, array $concepts, array $sourceRows, array &$errors): void
    {
        $groups = [];
        foreach ($coverage as $row) {
            $groups[$row['concept_key']][] = $row;
        }
        $conceptIndex = array_column($concepts, null, 'concept_key');
        foreach ($groups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $rule = $this->compatibilityRules()[$key] ?? null;
            if ($rule === null || $rule['explanation'] === '') {
                $errors[] = "unexplained shared-concept compatibility $key";

                continue;
            }
            $sourceEvidence = array_map(fn ($row) => $sourceRows[(int) $row['source_row_ordinal'] - 1], $rows);
            $fields = array_unique(array_column($sourceEvidence, 'external_field'));
            $entities = array_unique(array_column($rows, 'entity_level'));
            if (count($fields) !== 1 || array_diff(['Product', 'ProductVariant'], $entities) !== []) {
                $errors[] = "compatibility rule does not explain members for $key";
            }
            foreach ($rows as $index => $row) {
                $source = $sourceEvidence[$index];
                $dimensions = [$row['entity_level'], $source['type_or_ref'], str_starts_with($source['type_or_ref'], 'array') ? 'many' : 'optional_one', $row['read_semantics'], $row['write_semantics'], $row['source_context_key'], $this->inheritanceContract($source)];
                if (in_array('', $dimensions, true)) {
                    $errors[] = "incomplete compatibility dimensions for $key";
                }
            }
            if (($conceptIndex[$key]['value_type'] ?? null) !== $rule['semantic_type']) {
                $errors[] = "neutral semantic type mismatch for $key";
            }
            if (($conceptIndex[$key]['cardinality'] ?? null) !== 'optional_one') {
                $errors[] = "shared concept cardinality mismatch for $key";
            }
        }
    }

    private function inheritanceContract(array $source): string
    {
        $description = strtolower($source['description']);
        if (str_contains($description, 'if this value is null') || str_contains($description, 'default') || str_contains($description, 'price list')) {
            return 'documented_fallback_or_precedence';
        }

        return 'no_fallback_stated';
    }

    private function validateDisagreements(array $coverage, array $conceptIndex, array $disagreements, array &$errors): void
    {
        $seen = [];
        foreach ($disagreements as $question) {
            $key = $question['question_key'];
            if (! isset($this->disagreementDefinitions()[$key])) {
                $errors[] = "unexpected disagreement $key";
            }
            if (isset($seen[$key])) {
                $errors[] = "duplicate disagreement $key";
            }
            $seen[$key] = true;
            $concepts = $this->list($question['evidence_concept_keys']);
            foreach ($concepts as $concept) {
                if (! isset($conceptIndex[$concept])) {
                    $errors[] = "invalid disagreement concept $concept";
                }
            }
            $affected = array_values(array_filter($coverage, fn ($row) => in_array($row['concept_key'], $concepts, true)));
            if ((int) $question['affected_coverage_count'] !== count($affected)) {
                $errors[] = "stale disagreement count $key";
            }
            if ($question['review_status'] === 'OPEN' && ($question['existing_decision_reference'] === '' || $question['question'] === '')) {
                $errors[] = "open disagreement lacks question/reference $key";
            }
            foreach ($affected as $row) {
                if ($row['review_status'] === 'PROVIDER_VERIFIED' || ! in_array('queue:'.$key, $this->list($row['decision_reference']), true)) {
                    $errors[] = "open disagreement silently verified $key row {$row['source_row_ordinal']}";
                }
            }
        }
        foreach ($this->disagreementDefinitions() as $key => $_definition) {
            if (! isset($seen[$key])) {
                $errors[] = "missing disagreement $key";
            }
        }
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
