<?php

namespace App\Support\CanonicalCoverage;

use RuntimeException;

final class AdobeCommerceCoverage
{
    public const BASE_COMMIT = '5be3ec03ec915e6831ad86777d5284aa083be206';

    public const MASTER = 'docs/data/adobe_commerce_v1_inventory_master.csv';

    public const STRUCTURED = 'docs/data/adobe_commerce_v1_structured_object_fields.csv';

    public const ALIASES = 'docs/data/adobe_commerce_v1_alias_groups.csv';

    public const CLUSTERS = 'docs/data/adobe_commerce_v1_capability_clusters.csv';

    public const SOURCES = 'docs/data/adobe_commerce_v1_inventory_source_matrix.csv';

    public const COVERAGE = 'docs/data/canonical-coverage/adobe.csv';

    public const CONCEPTS = 'docs/data/canonical-coverage/adobe-concepts.csv';

    public const DISAGREEMENTS = 'docs/data/canonical-coverage/adobe-disagreements.csv';

    public const MANIFEST = BigCommerceCoverage::MANIFEST;

    /** @return array<string, mixed> */
    public function generate(string $root): array
    {
        [$masterHeader, $master] = $this->readCsv("$root/".self::MASTER);
        [$structuredHeader, $structured] = $this->readCsv("$root/".self::STRUCTURED);
        [$aliasHeader, $aliases] = $this->readCsv("$root/".self::ALIASES);
        $this->assertDenominator($master, $structured, $aliases);
        $manifest = $this->upsertManifest($root, [
            $this->manifestRow($root, self::MASTER, count($master), 'Adobe inventory master v1'),
            $this->manifestRow($root, self::STRUCTURED, count($structured), 'Adobe structured object fields v1'),
            $this->manifestRow($root, self::ALIASES, count($aliases), 'Adobe alias groups v1'),
        ]);
        $manifestByFile = array_column($manifest, null, 'source_file');
        $aliasGroupsByKey = [];
        foreach ($aliases as $alias) {
            $aliasGroupsByKey[$alias['surface_key']][] = $alias['alias_group'];
        }

        $coverage = [];
        $masterCoverageByKey = [];
        foreach ($master as $index => $row) {
            $groups = $aliasGroupsByKey[$row['adobe_key_or_capability']] ?? [];
            sort($groups, SORT_STRING);
            $concept = $groups !== [] ? 'adobe:alias:'.$this->slug($groups[0]) : 'adobe:'.$this->slug($row['cluster']).':'.$this->slug($row['adobe_key_or_capability']);
            [$disposition, $owner, $representation] = $this->classifyMaster($row);
            $questions = $this->questionsForConcept($concept);
            $record = $this->coverageRow(
                $manifestByFile[self::MASTER]['snapshot_id'], self::MASTER, $index + 1, array_values($row),
                $row['source_surface'], $row['adobe_key_or_capability'], 'not_applicable',
                'edition='.$row['edition_scope'].';cluster='.$row['cluster'].';review='.$row['review_status'],
                'not_applicable', 'adobe:atom:master:'.$this->slug($row['adobe_key_or_capability']), $concept,
                $disposition, $owner, $representation, 'ProductOrProviderContext', $this->masterValueShape($row),
                $row['entry_kind'] === 'derived_projection' ? 'read_projection' : 'surface_defined',
                $row['entry_kind'] === 'derived_projection' ? 'read_only' : 'surface_defined',
                'not_applicable', 'not_applicable', 'adobe-master:'.$row['cluster'].':'.$row['adobe_key_or_capability'],
                $questions, $row['review_status'], 'Frozen Adobe master classification; no cross-platform equivalence asserted.'
            );
            $coverage[] = $record;
            $masterCoverageByKey[$row['adobe_key_or_capability']] = $record;
        }

        foreach ($structured as $index => $row) {
            $parentKey = $this->structuredParentKey($row['object_family']);
            $parent = $masterCoverageByKey[$parentKey] ?? null;
            if ($parent === null) {
                throw new RuntimeException("No top-level parent for structured family {$row['object_family']}");
            }
            $concept = 'adobe:structure:'.$this->slug($row['object_family']).':'.$this->slug($row['subfield']);
            $questions = $this->questionsForConcept($parent['concept_key']);
            $coverage[] = $this->coverageRow(
                $manifestByFile[self::STRUCTURED]['snapshot_id'], self::STRUCTURED, $index + 1, array_values($row),
                $row['source_surface'], $row['subfield'], $row['object_family'].'.'.$row['subfield'],
                'family='.$row['object_family'].';review='.$row['review_status'], $parent['coverage_id'],
                'adobe:atom:structure:'.$this->slug($row['object_family']).':'.$this->slug($row['subfield']), $concept,
                'STRUCTURE_MEMBER', $parent['owner_candidate'], $this->memberRole($row),
                'StructuredMember', $this->structuredValueShape($row), 'surface_defined', 'surface_defined',
                'not_applicable', 'not_applicable', 'adobe-structured:'.$row['object_family'].':'.$row['subfield'],
                $questions, $row['review_status'], $row['semantic_note']
            );
        }

        foreach ($aliases as $index => $row) {
            $target = $masterCoverageByKey[$row['surface_key']] ?? $this->firstAliasTarget($row['alias_group'], $aliases, $masterCoverageByKey);
            $concept = 'adobe:alias:'.$this->slug($row['alias_group']);
            $questions = $this->questionsForConcept($concept);
            $coverage[] = $this->coverageRow(
                $manifestByFile[self::ALIASES]['snapshot_id'], self::ALIASES, $index + 1, array_values($row),
                $row['source_surface'], $row['surface_key'], 'alias_group.'.$row['alias_group'].'.'.$row['surface_key'],
                'alias_group='.$row['alias_group'].';identity_rule='.$row['identity_rule'].';review='.$row['review_status'],
                'not_applicable', 'adobe:atom:alias:'.$this->slug($row['alias_group']).':'.$index, $concept,
                'ALIAS_REPRESENTATION', $target['owner_candidate'], 'alias_with_'.$this->aliasRuleKind($row['identity_rule']),
                'ProviderRepresentation', 'surface_defined', 'surface_defined', 'surface_defined', 'not_applicable',
                $target['coverage_id'], 'adobe-alias:'.$row['alias_group'].':'.$row['surface_key'], $questions,
                $row['review_status'], $row['semantic_concept'].'; '.$row['identity_rule']
            );
        }

        $concepts = $this->buildConcepts($coverage);
        $disagreements = $this->buildDisagreements($coverage);
        $this->writeCsv("$root/".self::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);
        $this->writeCsv("$root/".self::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);
        $this->writeCsv("$root/".self::CONCEPTS, BigCommerceCoverage::CONCEPT_HEADER, $concepts);
        $this->writeCsv("$root/".self::DISAGREEMENTS, BigCommerceCoverage::DISAGREEMENT_HEADER, $disagreements);

        return $this->metrics($coverage, count($concepts));
    }

    /** @return array<string, mixed> */
    public function validate(string $root): array
    {
        [$manifestHeader, $manifest] = $this->readCsv("$root/".self::MANIFEST);
        [$coverageHeader, $coverage] = $this->readCsv("$root/".self::COVERAGE);
        [$conceptHeader, $concepts] = $this->readCsv("$root/".self::CONCEPTS);
        [$disagreementHeader, $disagreements] = $this->readCsv("$root/".self::DISAGREEMENTS);
        [$masterHeader, $master] = $this->readCsv("$root/".self::MASTER);
        [$structuredHeader, $structured] = $this->readCsv("$root/".self::STRUCTURED);
        [$aliasHeader, $aliases] = $this->readCsv("$root/".self::ALIASES);
        $this->assertDenominator($master, $structured, $aliases);
        $errors = [];
        if ($manifestHeader !== BigCommerceCoverage::MANIFEST_HEADER || $coverageHeader !== BigCommerceCoverage::COVERAGE_HEADER || $conceptHeader !== BigCommerceCoverage::CONCEPT_HEADER || $disagreementHeader !== BigCommerceCoverage::DISAGREEMENT_HEADER) {
            $errors[] = 'shared provider contract mismatch';
        }
        $manifestIndex = [];
        foreach ($manifest as $row) {
            $identity = $row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file'];
            if (isset($manifestIndex[$identity])) {
                $errors[] = "duplicate manifest identity $identity";
            }
            $manifestIndex[$identity] = $row;
        }
        foreach ([self::MASTER => $master, self::STRUCTURED => $structured, self::ALIASES => $aliases] as $file => $rows) {
            $entry = $manifestIndex['adobe_commerce'.BigCommerceCoverage::SEPARATOR.$file] ?? null;
            if ($entry === null) {
                $errors[] = "Adobe manifest entry missing $file";

                continue;
            }
            $bytes = file_get_contents("$root/$file");
            if ($entry['file_sha256'] !== hash('sha256', $bytes) || $entry['header_sha256'] !== $this->headerHash($bytes) || (int) $entry['row_count'] !== count($rows)) {
                $errors[] = "Adobe manifest integrity mismatch $file";
            }
        }

        $sourceRows = [self::MASTER => $master, self::STRUCTURED => $structured, self::ALIASES => $aliases];
        $seen = [];
        $coverageById = array_column($coverage, null, 'coverage_id');
        $conceptIndex = array_column($concepts, null, 'concept_key');
        $masterCoverageByKey = [];
        foreach ($coverage as $candidate) {
            if ($candidate['source_file'] === self::MASTER) {
                $masterCoverageByKey[$candidate['external_key']] = $candidate;
            }
        }
        $aliasGroupsByKey = [];
        foreach ($aliases as $alias) {
            $aliasGroupsByKey[$alias['surface_key']][] = $alias['alias_group'];
        }
        foreach ($coverage as $row) {
            $file = $row['source_file'];
            $ordinal = (int) $row['source_row_ordinal'];
            $source = $sourceRows[$file][$ordinal - 1] ?? null;
            if ($source === null) {
                $errors[] = "unknown physical source {$file}#{$ordinal}";

                continue;
            }
            $physical = "$file#$ordinal";
            if (isset($seen[$physical])) {
                $errors[] = "duplicate physical coverage $physical";
            }
            $seen[$physical] = true;
            $rowHash = $this->rowHash(array_values($source));
            $manifestRow = $manifestIndex['adobe_commerce'.BigCommerceCoverage::SEPARATOR.$file];
            $expectedId = hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$manifestRow['snapshot_id'], $file, (string) $ordinal, $rowHash]));
            if ($row['source_row_sha256'] !== $rowHash || $row['coverage_id'] !== $expectedId) {
                $errors[] = "provenance mismatch $physical";
            }
            if (! in_array($row['disposition'], BigCommerceCoverage::DISPOSITIONS, true) || ! isset($conceptIndex[$row['concept_key']])) {
                $errors[] = "classification/concept mismatch $physical";
            }
            if ($row['applicability_key'] !== 'not_applicable') {
                $errors[] = "dangling applicability key $physical";
            }
            if ($file === self::STRUCTURED && ($row['disposition'] !== 'STRUCTURE_MEMBER' || ! isset($coverageById[$row['parent_coverage_id']]))) {
                $errors[] = "orphan structured parent $physical";
            }
            if ($file === self::STRUCTURED) {
                $expectedParent = $masterCoverageByKey[$this->structuredParentKey($source['object_family'])]['coverage_id'] ?? null;
                $expectedConcept = 'adobe:structure:'.$this->slug($source['object_family']).':'.$this->slug($source['subfield']);
                if ($row['parent_coverage_id'] !== $expectedParent || $row['concept_key'] !== $expectedConcept) {
                    $errors[] = "incorrect structured parent/concept $physical";
                }
            }
            if ($file === self::ALIASES && ($row['disposition'] !== 'ALIAS_REPRESENTATION' || ! isset($coverageById[$row['alias_of_coverage_id']]) || ! str_contains($row['source_context_key'], 'identity_rule='))) {
                $errors[] = "invalid alias reference $physical";
            }
            if ($file === self::ALIASES && $row['concept_key'] !== 'adobe:alias:'.$this->slug($source['alias_group'])) {
                $errors[] = "incorrect alias concept $physical";
            }
            if ($file === self::ALIASES) {
                $expectedTarget = $masterCoverageByKey[$source['surface_key']] ?? $this->firstAliasTarget($source['alias_group'], $aliases, $masterCoverageByKey);
                if ($row['alias_of_coverage_id'] !== $expectedTarget['coverage_id']) {
                    $errors[] = "incorrect alias target $physical";
                }
            }
            if ($file === self::MASTER) {
                $groups = $aliasGroupsByKey[$source['adobe_key_or_capability']] ?? [];
                sort($groups, SORT_STRING);
                $expectedConcept = $groups !== [] ? 'adobe:alias:'.$this->slug($groups[0]) : 'adobe:'.$this->slug($source['cluster']).':'.$this->slug($source['adobe_key_or_capability']);
                if ($row['concept_key'] !== $expectedConcept) {
                    $errors[] = "incorrect master concept $physical";
                }
            }
        }
        if (count($seen) !== 411 || count($coverage) !== 411) {
            $errors[] = 'Adobe physical coverage mismatch';
        }
        $this->validateConcepts($coverage, $concepts, $errors);
        $this->validateDisagreements($coverage, $conceptIndex, $disagreements, $errors);
        $this->validateSupportingMetadata($root, $master, $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $this->metrics($coverage, count($concepts)) + [
            'orphan_structured_parent_count' => 0, 'invalid_alias_reference_count' => 0,
            'safe_merge_conflicts_unexplained' => 0, 'open_disagreements_with_verified_rows' => 0,
            'invalid_disagreement_refs' => 0, 'dangling_applicability_keys' => 0,
            'manifest_provider_rows_preserved' => 'PASS',
        ];
    }

    private function coverageRow(string $snapshot, string $file, int $ordinal, array $values, string $surface, string $key, string $path, string $context, string $parent, string $atom, string $concept, string $disposition, string $owner, string $representation, string $entity, string $shape, string $read, string $write, string $applicability, string $aliasTarget, string $evidence, array $questions, string $sourceReview, string $note): array
    {
        $rowHash = $this->rowHash($values);

        return array_combine(BigCommerceCoverage::COVERAGE_HEADER, [
            hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$snapshot, $file, (string) $ordinal, $rowHash])),
            $snapshot, 'adobe_commerce', $file, (string) $ordinal, $rowHash, 'Adobe Commerce 2.4.9 / services current at frozen snapshot',
            $surface, str_contains($atom, ':structure:') ? explode(':', $atom)[3] : 'product_capability', $key, $path, $context,
            $parent, $atom, $concept, $disposition, $owner, $representation, $entity, $shape, $read, $write,
            $applicability, $aliasTarget, $evidence,
            $questions === [] ? 'not_applicable' : implode('|', array_map(fn ($question) => 'queue:'.$question, $questions)),
            $questions === [] ? 'PROVIDER_VERIFIED' : 'DEFERRED_REVIEW', "$sourceReview; $note",
        ]);
    }

    private function classifyMaster(array $row): array
    {
        $cluster = $row['cluster'];
        $owners = [
            'pricing' => 'Pricing', 'b2b_pricing' => 'Pricing', 'tax_configuration' => 'PricingTax',
            'inventory_availability' => 'Availability', 'inventory_topology_msi' => 'Inventory',
            'media' => 'Media', 'taxonomy_and_product_relations' => 'ProductAssociation',
            'configurable_family' => 'VariantComposition', 'bundle_composition' => 'BundleComposition',
            'grouped_composition' => 'GroupedComposition', 'downloadable_assets' => 'DownloadableComposition',
            'customizable_options' => 'OrderCustomization', 'gift_card_product' => 'GiftCard',
            'b2b_catalog_visibility' => 'SharedCatalog', 'gift_and_merchandising' => 'Merchandising',
            'fulfillment_policy' => 'Returns', 'product_type_family' => 'ProductTypeExecution',
            'dynamic_attributes' => 'DynamicField', 'select_multiselect_options' => 'FieldDictionary',
        ];
        $clusterOwner = $owners[$cluster] ?? 'Connector';
        if ($cluster === 'catalog_scope_and_connector_context' || $cluster === 'localization_store_scope') {
            return ['CHANNEL_SEMANTIC', 'Connector', 'provider_scope_context'];
        }
        if ($cluster === 'attribute_schema') {
            return ['APPLICABILITY_METADATA', 'AttributeSchema', 'schema_or_attribute_set_context'];
        }
        if ($cluster === 'storefront_derived_readonly' || $row['entry_kind'] === 'derived_projection') {
            return ['DERIVED_PROJECTION', $clusterOwner, 'read_projection'];
        }
        if ($row['entry_kind'] === 'external_system_metadata') {
            if (in_array($row['adobe_key_or_capability'], ['created_at', 'updated_at', 'has_options', 'required_options'], true)) {
                return ['DERIVED_PROJECTION', $clusterOwner, 'provider_metadata_projection'];
            }

            return ['EXTERNAL_IDENTITY', $clusterOwner, 'external_record_identity'];
        }
        if (isset($owners[$cluster]) || in_array($row['entry_kind'], ['domain_value', 'structured_capability'], true)) {
            $owner = $owners[$cluster] ?? 'ProductDomain';
            if (in_array($row['adobe_key_or_capability'], ['categories', 'category_link'], true)) {
                $owner = 'Category';
            }

            return ['DOMAIN_CAPABILITY', $owner, 'provider_domain_capability'];
        }

        return ['REUSABLE_SEMANTIC', 'ProductData', 'provider_field_candidate'];
    }

    private function structuredParentKey(string $family): string
    {
        return [
            'attribute_option' => 'attribute_options', 'attribute_storefront_properties' => 'attribute_definition',
            'authoritative_attribute_metadata' => 'attribute_definition', 'swatch_data' => 'attribute_options',
            'dynamic_attribute_value' => 'custom_attributes', 'base_price' => 'base_price_storage',
            'special_price' => 'special_price_storage', 'cost_value' => 'cost_storage', 'tier_price' => 'tier_prices',
            'bundle_import_value' => 'bundle_values', 'bundle_product_link' => 'bundle_product_links',
            'bundle_product_option' => 'bundle_product_options', 'category_link' => 'category_link',
            'configurable_product_link' => 'configurable_product_links', 'configurable_product_option' => 'configurable_product_options',
            'configurable_product_option_value' => 'configurable_product_options', 'customizable_file_value' => 'custom_option_definition',
            'customizable_option' => 'custom_option_definition', 'customizable_option_value' => 'custom_options',
            'downloadable_link' => 'downloadable_link', 'downloadable_sample' => 'downloadable_sample',
            'giftcard_amount' => 'giftcard_amounts', 'giftcard_amount_list' => 'giftcard_amounts',
            'grouped_product_item' => 'grouped_product_item', 'inventory_sales_channel' => 'inventory_sales_channel',
            'inventory_source' => 'inventory_source', 'inventory_source_item' => 'source_item', 'inventory_stock' => 'inventory_stock',
            'inventory_stock_source_link' => 'inventory_stock_source_link', 'media_content' => 'media_gallery_entries',
            'media_gallery_entry' => 'media_gallery_entries', 'media_video_content' => 'media_video', 'product_link' => 'product_links',
        ][$family] ?? throw new RuntimeException("Unknown structured family $family");
    }

    private function memberRole(array $row): string
    {
        $key = $row['subfield'];
        if (in_array($key, ['id', 'uid', 'option_id', 'value_id', 'attribute_id', 'link_id', 'sample_id', 'stock_id'], true)) {
            return 'identity_member';
        }
        if (in_array($key, ['sku', 'product_sku', 'parent_sku', 'child_sku', 'linked_product_sku', 'source_code', 'website_id', 'store_id', 'code'], true)) {
            return 'reference_member';
        }
        if (in_array($key, ['price', 'cost', 'price_type', 'website_value'], true)) {
            return 'price_modifier_member';
        }
        if (in_array($key, ['position', 'sort_order', 'priority'], true)) {
            return 'ordering_member';
        }
        if (in_array($key, ['required', 'is_require', 'is_required'], true)) {
            return 'requiredness_member';
        }
        if (in_array($key, ['max_characters', 'file_extension', 'image_size_x', 'image_size_y'], true)) {
            return 'constraint_member';
        }
        if (in_array($key, ['types', 'media_type', 'disabled', 'label'], true)) {
            return 'media_role_member';
        }

        return 'value_or_context_member';
    }

    private function firstAliasTarget(string $group, array $aliases, array $master): array
    {
        foreach ($aliases as $alias) {
            if ($alias['alias_group'] === $group && isset($master[$alias['surface_key']])) {
                return $master[$alias['surface_key']];
            }
        }
        $fallback = ['tier_price_customer_group' => 'tier_prices'][$group] ?? null;
        if ($fallback !== null && isset($master[$fallback])) {
            return $master[$fallback];
        }
        throw new RuntimeException("Alias group has no top-level target: $group");
    }

    private function aliasRuleKind(string $rule): string
    {
        return str_contains(strtolower($rule), 'raw') || str_contains(strtolower($rule), 'resolve') || str_contains(strtolower($rule), 'translate') ? 'transformation' : 'semantic_identity_rule';
    }

    private function buildConcepts(array $coverage): array
    {
        $groups = [];
        foreach ($coverage as $row) {
            $groups[$row['concept_key']][] = $row;
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $key => $rows) {
            $first = $rows[0];
            $result[] = array_combine(BigCommerceCoverage::CONCEPT_HEADER, [
                $key, str_replace(['adobe:', '_'], ['', ' '], $key), 'Adobe-local semantic represented by frozen provider evidence.',
                count(array_unique(array_column($rows, 'entity_level'))) > 1 ? 'ProviderContextual' : $first['entity_level'],
                $first['value_shape'], count($rows) > 1 ? 'context_dependent' : 'optional_one', 'not_applicable',
                in_array($first['disposition'], ['DERIVED_PROJECTION', 'EXTERNAL_IDENTITY'], true) ? 'provider_owned' : 'merchant_or_domain',
                implode('|', array_unique(array_column($rows, 'read_semantics'))), implode('|', array_unique(array_column($rows, 'write_semantics'))),
                'provider_context', $first['owner_candidate'], $first['representation_candidate'], 'store_scope_not_localization',
                'provider_controlled_or_typed', $this->conceptStatus($first['disposition']), 'adobe_commerce', (string) count($rows),
                'not_applicable', 'not_applicable', 'not_applicable', $first['decision_reference'], 'supported', $first['review_status'],
                count($rows) > 1 ? 'Multi-row concept permitted by frozen Adobe alias group; surface transformation remains row-local.' : 'Provider-local concept; no cross-platform merge asserted.',
            ]);
        }

        return $result;
    }

    private function disagreementDefinitions(): array
    {
        return [
            'adobe_status_lifecycle' => ['status_lifecycle', 'Does Adobe enabled/disabled status map to platform lifecycle?', 'adobe:alias:status', 'unresolved-status-lifecycle'],
            'adobe_tax_portability' => ['tax', 'What portable tax semantic and owner represent Adobe tax ID/name?', 'adobe:alias:tax_class', 'unresolved-tax-owner'],
            'adobe_map_policy' => ['map', 'How should Adobe MAP policy be persisted?', 'adobe:pricing:map_price|adobe:pricing:map_enabled', 'unresolved-map-persistence'],
            'adobe_msrp_equivalence' => ['reference_price', 'What is equivalent between Adobe MSRP and portable RRP/list price?', 'adobe:pricing:msrp_price|adobe:pricing:msrp_display_actual_price_type', 'unresolved-reference-price-equivalence'],
            'adobe_weight_semantic' => ['weight', 'Is Adobe weight item, shipping, net, package, or gross weight?', 'adobe:core_product_scalar:weight', 'DEC-009'],
            'adobe_store_scope_localization' => ['localization', 'How do store-view overrides differ from localization?', 'adobe:localization_store_scope:store_view_overrides', 'unresolved-localization-policy'],
            'adobe_rma_write_identity' => ['rma', 'What is the exact supported Admin REST write identity for RMA eligibility?', 'adobe:fulfillment_policy:rma_eligibility|adobe:fulfillment_policy:is_returnable', 'unresolved-rma-write-identity'],
            'adobe_gift_wrap_write_identity' => ['gift_wrap', 'What is the exact supported Admin REST write identity for gift wrapping?', 'adobe:gift_and_merchandising:gift_wrapping_capability|adobe:gift_and_merchandising:gift_wrapping_available|adobe:gift_and_merchandising:gift_wrapping_price', 'unresolved-gift-wrap-write-identity'],
            'adobe_customization_architecture' => ['customization', 'How do Adobe customizable options map to universal customization architecture?', 'adobe:customizable_options:custom_options|adobe:customizable_options:custom_option_definition', 'unresolved-customization-architecture'],
            'adobe_composition_mapping' => ['composition', 'How do configurable, bundle, and grouped capabilities map without flattening?', 'adobe:alias:configurable_composition|adobe:alias:bundle_composition|adobe:alias:grouped_composition', 'unresolved-composition-mapping'],
            'adobe_product_type_execution' => ['product_type', 'How does Adobe execution Product Type relate to portable classifications?', 'adobe:alias:product_type', 'unresolved-product-type-semantics'],
            'adobe_dynamic_eav_promotion' => ['dynamic_eav', 'Which discovered Adobe EAV attributes qualify for reusable promotion and applicability?', 'adobe:dynamic_attributes:additional_attributes|adobe:dynamic_attributes:custom_attributes', 'unresolved-eav-promotion'],
            'adobe_b2b_shared_catalog' => ['b2b_shared_catalog', 'What portions of Shared Catalog membership and pricing are portable?', 'adobe:b2b_catalog_visibility:shared_catalog_product_membership|adobe:b2b_pricing:shared_catalog_tier_prices', 'unresolved-shared-catalog-portability'],
            'adobe_catalog_projection' => ['catalog_service', 'Which Catalog Service projections are semantically equivalent to authoritative domain values?', 'adobe:storefront_derived_readonly:catalog_service_price|adobe:storefront_derived_readonly:catalog_service_stock_flags', 'unresolved-projection-equivalence'],
        ];
    }

    private function questionsForConcept(string $concept): array
    {
        $result = [];
        foreach ($this->disagreementDefinitions() as $key => $definition) {
            if (in_array($concept, explode('|', $definition[2]), true)) {
                $result[] = $key;
            }
        }

        return $result;
    }

    private function buildDisagreements(array $coverage): array
    {
        $result = [];
        foreach ($this->disagreementDefinitions() as $key => [$family, $question, $conceptList, $reference]) {
            $concepts = explode('|', $conceptList);
            $count = count(array_filter($coverage, fn ($row) => in_array($row['concept_key'], $concepts, true)));
            $result[] = array_combine(BigCommerceCoverage::DISAGREEMENT_HEADER, [$key, $family, $question, $conceptList, (string) $count, $reference, 'OPEN', 'Adobe provider fate is retained; final portable decision is deferred.']);
        }

        return $result;
    }

    private function validateConcepts(array $coverage, array $concepts, array &$errors): void
    {
        foreach ($concepts as $concept) {
            $rows = array_values(array_filter($coverage, fn ($row) => $row['concept_key'] === $concept['concept_key']));
            if ($rows === [] || (int) $concept['evidence_coverage_count'] !== count($rows)) {
                $errors[] = 'orphan/stale concept '.$concept['concept_key'];
            }
            if (count($rows) > 1 && ! str_starts_with($concept['concept_key'], 'adobe:alias:')) {
                $errors[] = 'unexplained shared Adobe concept '.$concept['concept_key'];
            }
            if (count($rows) > 1 && ! str_contains($concept['review_note'], 'frozen Adobe alias group')) {
                $errors[] = 'missing Adobe compatibility rule '.$concept['concept_key'];
            }
        }
    }

    private function validateDisagreements(array $coverage, array $conceptIndex, array $questions, array &$errors): void
    {
        $seen = [];
        foreach ($questions as $question) {
            $key = $question['question_key'];
            $seen[$key] = true;
            if (! isset($this->disagreementDefinitions()[$key])) {
                $errors[] = "unexpected Adobe disagreement $key";
            }
            $concepts = explode('|', $question['evidence_concept_keys']);
            foreach ($concepts as $concept) {
                if (! isset($conceptIndex[$concept])) {
                    $errors[] = "invalid Adobe disagreement concept $concept";
                }
            }
            $rows = array_values(array_filter($coverage, fn ($row) => in_array($row['concept_key'], $concepts, true)));
            if ((int) $question['affected_coverage_count'] !== count($rows)) {
                $errors[] = "stale Adobe disagreement $key";
            }
            foreach ($rows as $row) {
                if ($row['review_status'] === 'PROVIDER_VERIFIED' || ! in_array('queue:'.$key, explode('|', $row['decision_reference']), true)) {
                    $errors[] = "open Adobe disagreement silently verified $key";
                }
            }
        }
        foreach ($this->disagreementDefinitions() as $key => $_) {
            if (! isset($seen[$key])) {
                $errors[] = "missing Adobe disagreement $key";
            }
        }
    }

    private function validateSupportingMetadata(string $root, array $master, array &$errors): void
    {
        [, $clusters] = $this->readCsv("$root/".self::CLUSTERS);
        [, $sources] = $this->readCsv("$root/".self::SOURCES);
        $knownClusters = array_column($clusters, null, 'cluster');
        foreach ($master as $row) {
            if (! isset($knownClusters[$row['cluster']])) {
                $errors[] = 'unknown Adobe cluster '.$row['cluster'];
            }
        }
        foreach ($clusters as $cluster) {
            $actual = count(array_filter($master, fn ($row) => $row['cluster'] === $cluster['cluster']));
            if ((int) $cluster['inventory_count'] !== $actual) {
                $errors[] = 'Adobe cluster count mismatch '.$cluster['cluster'];
            }
        }
        if (count($sources) !== 14 || in_array('', array_column($sources, 'surface'), true)) {
            $errors[] = 'Adobe source matrix contract mismatch';
        }
    }

    private function metrics(array $coverage, int $conceptCount): array
    {
        $byFile = array_count_values(array_column($coverage, 'source_file'));

        return [
            'master_rows' => $byFile[self::MASTER] ?? 0, 'structured_rows' => $byFile[self::STRUCTURED] ?? 0,
            'alias_rows' => $byFile[self::ALIASES] ?? 0, 'coverage_rows' => count($coverage), 'concepts' => $conceptCount,
            'coverage_ratio' => (float) (count($coverage) / 411), 'classification_ratio' => (float) (count(array_filter($coverage, fn ($row) => $row['disposition'] !== '')) / count($coverage)),
            'concept_link_ratio' => (float) (count(array_filter($coverage, fn ($row) => $row['concept_key'] !== '')) / count($coverage)),
            'terminal_rationale_ratio' => 1.0,
            'silent_drop_count' => 411 - count($coverage), 'dispositions' => array_count_values(array_column($coverage, 'disposition')),
        ];
    }

    private function manifestRow(string $root, string $file, int $count, string $basis): array
    {
        $bytes = file_get_contents("$root/$file");
        $hash = hash('sha256', $bytes);

        return ['snapshot_id' => 'adobe-v1-'.substr($hash, 0, 16), 'repository_commit' => self::BASE_COMMIT, 'platform' => 'adobe_commerce', 'source_file' => $file, 'file_sha256' => $hash, 'header_sha256' => $this->headerHash($bytes), 'row_count' => (string) $count, 'schema_version_basis' => $basis, 'captured_at' => '2026-09-07'];
    }

    private function upsertManifest(string $root, array $providerRows): array
    {
        $path = "$root/".self::MANIFEST;
        $rows = [];
        if (is_file($path)) {
            [$header, $rows] = $this->readCsv($path);
            if ($header !== BigCommerceCoverage::MANIFEST_HEADER) {
                throw new RuntimeException('manifest contract mismatch');
            }
        }
        $index = [];
        foreach ($rows as $row) {
            $key = $row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file'];
            if (isset($index[$key])) {
                throw new RuntimeException("duplicate manifest identity $key");
            }
            $index[$key] = $row;
        }
        foreach ($providerRows as $row) {
            $index[$row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file']] = $row;
        }
        ksort($index, SORT_STRING);

        return array_values($index);
    }

    private function assertDenominator(array $master, array $structured, array $aliases): void
    {
        if (count($master) !== 183 || count($structured) !== 189 || count($aliases) !== 39) {
            throw new RuntimeException('Adobe frozen source drift');
        }
    }

    private function masterValueShape(array $row): string
    {
        return in_array($row['entry_kind'], ['structured_capability', 'domain_value'], true) ? 'provider_typed_or_structured' : 'scalar_or_context';
    }

    private function structuredValueShape(array $row): string
    {
        return $row['entry_kind'] === 'structured_capability' ? 'structured' : 'provider_typed';
    }

    private function conceptStatus(string $disposition): string
    {
        return match ($disposition) {
            'REUSABLE_SEMANTIC' => 'reusable_candidate', 'DOMAIN_CAPABILITY' => 'domain', 'CHANNEL_SEMANTIC' => 'channel', 'DERIVED_PROJECTION' => 'projection', 'EXTERNAL_IDENTITY', 'APPLICABILITY_METADATA' => 'governance', 'STRUCTURE_MEMBER' => 'structure', 'ALIAS_REPRESENTATION' => 'representation', default => 'deferred'
        };
    }

    private function rowHash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function headerHash(string $bytes): string
    {
        return hash('sha256', strstr($bytes, "\n", true)."\n");
    }

    private function slug(string $value): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value)), '_');
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
            if ($values !== [null]) {
                $rows[] = array_combine($header, $values);
            }
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
        }
        fclose($handle);
    }
}
