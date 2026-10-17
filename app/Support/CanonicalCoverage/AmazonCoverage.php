<?php

namespace App\Support\CanonicalCoverage;

use RuntimeException;

final class AmazonCoverage
{
    public const BASE_COMMIT = '5be3ec03ec915e6831ad86777d5284aa083be206';

    public const META = 'docs/data/amazon_product_type_definitions_meta_model_inventory.csv';

    public const LUGGAGE = 'docs/data/amazon_listings_v1_public_ptd_luggage_inventory.csv';

    public const COVERAGE = 'docs/data/canonical-coverage/amazon.csv';

    public const CONCEPTS = 'docs/data/canonical-coverage/amazon-concepts.csv';

    public const DISAGREEMENTS = 'docs/data/canonical-coverage/amazon-disagreements.csv';

    public const MANIFEST = BigCommerceCoverage::MANIFEST;

    public const META_HASH = '00d0d7f2b2de0fe01b0980d96a1211da337458eb21515e40038f46582cdc0868';

    public const LUGGAGE_HASH = 'f3cfa6310484175d58df984a63acc75b02e1266481957c22a275e2d393dd9634';

    public function generate(string $root): array
    {
        [, $meta] = $this->readCsv("$root/".self::META);
        [, $luggage] = $this->readCsv("$root/".self::LUGGAGE);
        $this->assertSources($root, $meta, $luggage);
        $manifest = $this->upsertManifest($root, [
            $this->manifestRow($root, self::META, 'Amazon Product Type Definitions meta-model'),
            $this->manifestRow($root, self::LUGGAGE, 'Amazon Listings v1 public LUGGAGE PTD example'),
        ]);
        $manifestByFile = array_column($manifest, null, 'source_file');
        $coverage = [];
        foreach ($meta as $index => $row) {
            [$disposition, $owner, $representation] = $this->classifyMeta($row);
            $concept = 'amazon:ptd_meta:'.$this->slug($row['model']).':'.$this->slug($row['field']);
            $coverage[] = $this->coverageRow($manifestByFile[self::META]['snapshot_id'], self::META, $index + 1, $row, $row['model'], $row['field'],
                'model='.$row['model'].';verified_at='.$row['verified_at'], $concept, $disposition, $owner, $representation,
                $this->shape($row['type_or_ref']), 'schema_discovery_metadata', 'not_product_write_capability', $this->questions($row['field'], true), $row['source_url']);
        }
        foreach ($luggage as $index => $row) {
            [$disposition, $owner, $representation, $conceptSuffix] = $this->classifyLuggage($row);
            $concept = 'amazon:luggage:'.$conceptSuffix;
            $coverage[] = $this->coverageRow($manifestByFile[self::LUGGAGE]['snapshot_id'], self::LUGGAGE, $index + 1, $row, 'LUGGAGE PTD', $row['external_field'],
                $this->luggageContext($row), $concept, $disposition, $owner, $representation, 'ptd_defined_structured_property',
                'ptd_schema_presence_not_live_listing_read', 'ptd_conditioned_write_not_unconditional', $this->questions($row['external_field'], false), $row['source_url'].'#'.$row['schema_version_token']);
        }
        $concepts = $this->buildConcepts($coverage);
        $disagreements = $this->buildDisagreements($coverage);
        $this->writeCsv("$root/".self::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);
        $this->writeCsv("$root/".self::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);
        $this->writeCsv("$root/".self::CONCEPTS, BigCommerceCoverage::CONCEPT_HEADER, $concepts);
        $this->writeCsv("$root/".self::DISAGREEMENTS, BigCommerceCoverage::DISAGREEMENT_HEADER, $disagreements);

        return $this->metrics($coverage, count($concepts), count($disagreements));
    }

    public function validate(string $root): array
    {
        [, $meta] = $this->readCsv("$root/".self::META);
        [, $luggage] = $this->readCsv("$root/".self::LUGGAGE);
        [$mh, $manifest] = $this->readCsv("$root/".self::MANIFEST);
        [$ch, $coverage] = $this->readCsv("$root/".self::COVERAGE);
        [$coh, $concepts] = $this->readCsv("$root/".self::CONCEPTS);
        [$dh, $disagreements] = $this->readCsv("$root/".self::DISAGREEMENTS);
        $this->assertSources($root, $meta, $luggage);
        $errors = [];
        if ($mh !== BigCommerceCoverage::MANIFEST_HEADER || $ch !== BigCommerceCoverage::COVERAGE_HEADER || $coh !== BigCommerceCoverage::CONCEPT_HEADER || $dh !== BigCommerceCoverage::DISAGREEMENT_HEADER) {
            $errors[] = 'shared provider contract mismatch';
        }
        $manifestIndex = [];
        foreach ($manifest as $row) {
            $key = $row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file'];
            if (isset($manifestIndex[$key])) {
                $errors[] = "duplicate manifest identity $key";
            }
            $manifestIndex[$key] = $row;
        }
        foreach ($this->requiredPreAmazonManifestKeys() as $requiredKey) {
            if (! isset($manifestIndex[$requiredKey])) {
                $errors[] = "accepted pre-Amazon manifest identity missing $requiredKey";
            }
        }
        $sources = [self::META => $meta, self::LUGGAGE => $luggage];
        $manifestBasis = [
            self::META => 'Amazon Product Type Definitions meta-model',
            self::LUGGAGE => 'Amazon Listings v1 public LUGGAGE PTD example',
        ];
        foreach ($sources as $file => $rows) {
            $entry = $manifestIndex['amazon'.BigCommerceCoverage::SEPARATOR.$file] ?? null;
            $expectedManifest = $this->manifestRow($root, $file, $manifestBasis[$file]);
            if ($entry === null || $entry !== $expectedManifest) {
                $errors[] = "Amazon manifest mismatch $file";
            }
        }
        $conceptIndex = array_column($concepts, null, 'concept_key');
        $seen = [];
        foreach ($coverage as $row) {
            $file = $row['source_file'];
            $ordinal = (int) $row['source_row_ordinal'];
            $source = $sources[$file][$ordinal - 1] ?? null;
            if ($source === null) {
                $errors[] = "unknown Amazon row $file#$ordinal";

                continue;
            }
            $physical = "$file#$ordinal";
            if (isset($seen[$physical])) {
                $errors[] = "duplicate Amazon row $physical";
            }
            $seen[$physical] = true;
            $entry = $manifestIndex['amazon'.BigCommerceCoverage::SEPARATOR.$file];
            $hash = $this->rowHash(array_values($source));
            $id = hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$entry['snapshot_id'], $file, (string) $ordinal, $hash]));
            if ($row['source_row_sha256'] !== $hash || $row['coverage_id'] !== $id) {
                $errors[] = "Amazon provenance mismatch $physical";
            }
            if (! isset($conceptIndex[$row['concept_key']]) || ! in_array($row['disposition'], BigCommerceCoverage::DISPOSITIONS, true)) {
                $errors[] = "Amazon concept/disposition mismatch $physical";
            }
            if ($row['applicability_key'] !== 'not_applicable') {
                $errors[] = "dangling Amazon applicability $physical";
            }
            if ($file === self::META) {
                [$d, $o, $r] = $this->classifyMeta($source);
                if ($row['disposition'] !== $d || $row['owner_candidate'] !== $o || $row['representation_candidate'] !== $r || $row['read_semantics'] !== 'schema_discovery_metadata' || $row['write_semantics'] !== 'not_product_write_capability') {
                    $errors[] = "Amazon meta-model contract mismatch $physical";
                }
            } else {
                [$d, $o, $r] = $this->classifyLuggage($source);
                if ($row['disposition'] !== $d || $row['owner_candidate'] !== $o || $row['representation_candidate'] !== $r || $row['source_context_key'] !== $this->luggageContext($source)) {
                    $errors[] = "Amazon PTD semantic/context mismatch $physical";
                }
                if ($row['read_semantics'] !== 'ptd_schema_presence_not_live_listing_read' || $row['write_semantics'] !== 'ptd_conditioned_write_not_unconditional') {
                    $errors[] = "Amazon PTD capability overclaim $physical";
                }
            }
        }
        if (count($seen) !== 101 || count($coverage) !== 101) {
            $errors[] = 'Amazon physical coverage mismatch';
        }
        foreach ($concepts as $concept) {
            $evidence = array_filter($coverage, fn ($r) => $r['concept_key'] === $concept['concept_key']);
            if ((int) $concept['evidence_coverage_count'] !== count($evidence) || $evidence === []) {
                $errors[] = 'Amazon concept evidence mismatch '.$concept['concept_key'];
            }
        }
        $this->validateDisagreements($coverage, $conceptIndex, $disagreements, $errors);
        $semantic = $this->semanticMetrics($coverage);
        foreach ($semantic as $name => $count) {
            if ($count !== 0) {
                $errors[] = "Amazon semantic validation failed $name=$count";
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $this->metrics($coverage, count($concepts), count($disagreements)) + [
            'duplicate_physical_source_row_count' => 0, 'source_hash_mismatch_count' => 0,
            'open_disagreements_with_verified_rows' => 0, 'invalid_disagreement_refs' => 0,
            'dangling_applicability_keys' => $semantic['invented_applicability_key_count'], 'manifest_provider_rows_preserved' => 'PASS',
        ] + $semantic;
    }

    private function classifyMeta(array $row): array
    {
        if ($row['model'] === 'SchemaLink') {
            return ['TRANSPORT_MECHANIC', 'ProviderSchemaDiscovery', $row['field'] === 'link' ? 'schema_retrieval_link' : 'schema_content_checksum'];
        }

        return ['APPLICABILITY_METADATA', 'ProviderSchemaDiscovery', match (true) {
            in_array($row['field'], ['marketplaceIds'], true) => 'marketplace_applicability',
            in_array($row['field'], ['requirements', 'requirementsEnforced'], true) => 'requirements_set_applicability',
            in_array($row['field'], ['propertyGroups', 'propertyNames'], true) => 'schema_property_grouping',
            in_array($row['field'], ['version', 'latest', 'releaseCandidate', 'productTypeVersion'], true) => 'schema_version_metadata',
            in_array($row['field'], ['displayName', 'title', 'description', 'locale'], true) => 'schema_display_localization_metadata',
            default => 'product_type_schema_applicability',
        }];
    }

    private function classifyLuggage(array $row): array
    {
        $k = $row['external_field'];
        if (preg_match('/^(main|other)_offer_image_locator/', $k)) {
            return ['DOMAIN_CAPABILITY', 'Media', str_starts_with($k, 'main') ? 'offer_media_primary' : 'offer_media_additional', 'offer_media:'.(str_starts_with($k, 'main') ? 'primary' : 'additional')];
        }
        if (preg_match('/^(main|other|swatch)_product_image_locator/', $k)) {
            return ['DOMAIN_CAPABILITY', 'Media', str_starts_with($k, 'main') ? 'product_media_primary' : (str_starts_with($k, 'swatch') ? 'product_media_swatch' : 'product_media_additional'), 'product_media:'.(str_starts_with($k, 'main') ? 'primary' : (str_starts_with($k, 'swatch') ? 'swatch' : 'additional'))];
        }

        return match ($k) {
            'fulfillment_channel_availability' => ['DOMAIN_CAPABILITY', 'Availability', 'amazon_fulfillment_availability', $k],
            'purchasable_offer' => ['DOMAIN_CAPABILITY', 'Pricing', 'amazon_purchasable_offer_structured_envelope', $k],
            'list_price' => ['DOMAIN_CAPABILITY', 'Pricing', 'recommended_retail_price_evidence', $k],
            'condition_type' => ['REUSABLE_SEMANTIC', 'ProductVariantData', 'product_condition_enum', $k],
            'condition_note' => ['CHANNEL_SEMANTIC', 'ListingCondition', 'amazon_listing_condition_note', $k],
            'product_tax_code' => ['DEFER_DECISION', 'PricingOrCompliance', 'tax_classification_candidate', $k],
            'merchant_release_date' => ['DOMAIN_CAPABILITY', 'Availability', 'merchant_release_availability_date', $k],
            'merchant_shipping_group' => ['CHANNEL_SEMANTIC', 'Connector', 'amazon_shipping_publication_context', $k],
            'max_order_quantity' => ['DEFER_DECISION', 'ProductOrB2BCommercial', 'maximum_order_quantity_candidate', $k],
            'gift_options' => ['DEFER_DECISION', 'ChannelOrCommercial', 'gift_options_capability', $k],
            'item_dimensions' => ['REUSABLE_SEMANTIC', 'ProductData', 'item_dimensions', $k],
            'item_package_dimensions' => ['DOMAIN_CAPABILITY', 'PackagingLogistics', 'package_dimensions', $k],
            'item_package_weight' => ['DOMAIN_CAPABILITY', 'PackagingLogistics', 'package_weight', $k],
            'parentage_level', 'child_parent_sku_relationship', 'variation_theme' => ['DOMAIN_CAPABILITY', 'VariantComposition', 'amazon_variant_parentage_schema', 'variant_parentage:'.$k],
            'country_of_origin', 'warranty_description', 'item_weight' => ['REUSABLE_SEMANTIC', 'ProductData', $k === 'item_weight' ? 'item_weight' : $k.'_evidence', $k],
            'safety_data_sheet_url' => ['DOMAIN_CAPABILITY', 'ComplianceMedia', 'safety_data_sheet_document_reference', $k],
            'compliance_media' => ['DOMAIN_CAPABILITY', 'ComplianceMedia', 'compliance_media_reference', $k],
            'batteries_required', 'batteries_included', 'battery', 'num_batteries', 'number_of_lithium_metal_cells', 'number_of_lithium_ion_cells', 'lithium_battery', 'supplier_declared_dg_hz_regulation', 'hazmat', 'ghs', 'supplier_declared_material_regulation', 'california_proposition_65', 'pesticide_marking' => ['DOMAIN_CAPABILITY', 'Compliance', 'regulated_product_source_fact', 'compliance:'.$k],
            'item_name', 'brand', 'externally_assigned_product_identifier', 'model_number', 'manufacturer' => ['REUSABLE_SEMANTIC', $k === 'externally_assigned_product_identifier' ? 'ProductVariantData' : 'ProductData', $k === 'externally_assigned_product_identifier' ? 'commercial_identifier_gtin_family' : $k, $k],
            'supplier_declared_has_product_identifier_exemption' => ['CHANNEL_SEMANTIC', 'ConnectorGovernance', 'amazon_identifier_exemption_governance', $k],
            'merchant_suggested_asin' => ['CHANNEL_SEMANTIC', 'ConnectorIdentityReference', 'seller_suggested_asin_not_established_identity', $k],
            'item_type_keyword', 'item_type_name' => ['CHANNEL_SEMANTIC', 'ConnectorTaxonomy', 'amazon_taxonomy_context', 'amazon_taxonomy:'.$k],
            'bullet_point', 'special_feature' => ['REUSABLE_SEMANTIC', 'ProductData', 'product_highlight_evidence_distinct_representation', 'highlight:'.$k],
            'department', 'outer', 'fabric_type', 'lining_description', 'number_of_wheels', 'wheel', 'size_map' => ['CATEGORY_ATTRIBUTE', 'ProductTypeAttribute', 'luggage_ptd_scoped_attribute', $k],
            'style', 'product_description', 'target_gender', 'age_range_description', 'material', 'number_of_items', 'model_name', 'color', 'size', 'part_number' => ['REUSABLE_SEMANTIC', 'ProductData', 'ptd_scoped_'.$k, $k],
            default => throw new RuntimeException("Unreviewed Amazon LUGGAGE field $k"),
        };
    }

    private function disagreementDefinitions(): array
    {
        return [
            'amazon_ptd_applicability_versioning' => ['ptd_applicability', 'How should dynamic PTD applicability/version evidence be retained without becoming Product truth?', []],
            'amazon_taxonomy_category' => ['taxonomy', 'How does Amazon taxonomy map without becoming platform Category authority?', ['item_type_keyword', 'item_type_name']],
            'amazon_suggested_asin_identity' => ['identity', 'How should seller-suggested ASIN remain distinct from established connector identity?', ['merchant_suggested_asin']],
            'amazon_identifier_exemption' => ['identifier_governance', 'How should identifier exemption remain Amazon governance?', ['supplier_declared_has_product_identifier_exemption']],
            'amazon_offer_pricing' => ['pricing', 'How should the structured purchasable_offer envelope map while keeping independently verified list_price/RRP semantics distinct?', ['purchasable_offer']],
            'amazon_tax_classification' => ['tax', 'Which domain owns Amazon product tax classification?', ['product_tax_code']],
            'amazon_max_order_quantity' => ['commercial', 'Which Product/B2B/commercial domain owns maximum order quantity?', ['max_order_quantity']],
            'amazon_gift_options' => ['commercial', 'Which channel or commercial domain owns gift options?', ['gift_options']],
            'amazon_offer_product_media' => ['media_scope', 'How should offer-scoped media remain distinct from product media?', []],
            'amazon_variant_parentage' => ['variants', 'How should Amazon parentage schema map to VariantComposition?', ['parentage_level', 'child_parent_sku_relationship', 'variation_theme']],
            'amazon_item_package_dimensions' => ['dimensions', 'How should item/item-package dimensions and weights map without overclaiming DEC-009 packaging boundaries?', ['item_weight', 'item_dimensions', 'item_package_dimensions', 'item_package_weight']],
            'amazon_product_highlights' => ['content', 'How should special_feature remain distinct from the independently verified bullet_point/product-highlights representation?', ['special_feature']],
            'amazon_package_quantity_semantics' => ['packaging_count', 'How does Amazon number_of_items relate to package/container counts without becoming generic units-per-consumer-package?', ['number_of_items']],
            'amazon_compliance_portability' => ['compliance', 'Which regulated Amazon PTD facts are portable Compliance evidence?', []],
        ];
    }

    private function questions(string $key, bool $meta): array
    {
        $result = [];
        foreach ($this->disagreementDefinitions() as $question => [, , $keys]) {
            $match = in_array($key, $keys, true)
                || ($question === 'amazon_ptd_applicability_versioning' && $meta)
                || ($question === 'amazon_offer_product_media' && str_contains($key, '_image_locator'))
                || ($question === 'amazon_compliance_portability' && in_array($key, ['batteries_required', 'batteries_included', 'battery', 'num_batteries', 'number_of_lithium_metal_cells', 'number_of_lithium_ion_cells', 'lithium_battery', 'supplier_declared_dg_hz_regulation', 'hazmat', 'ghs', 'supplier_declared_material_regulation', 'california_proposition_65', 'pesticide_marking', 'safety_data_sheet_url', 'compliance_media'], true));
            if ($match) {
                $result[] = $question;
            }
        }

        return $result;
    }

    private function coverageRow(string $snapshot, string $file, int $ordinal, array $source, string $object, string $key, string $context, string $concept, string $disposition, string $owner, string $representation, string $shape, string $read, string $write, array $questions, string $evidence): array
    {
        $hash = $this->rowHash(array_values($source));

        return array_combine(BigCommerceCoverage::COVERAGE_HEADER, [
            hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$snapshot, $file, (string) $ordinal, $hash])), $snapshot, 'amazon', $file, (string) $ordinal, $hash,
            $file === self::META ? 'Product Type Definitions 2020-09-01' : $source['schema_version_token'], 'Selling Partner API PTD', $object, $key, 'not_applicable', $context,
            'not_applicable', 'amazon:atom:'.$this->slug($object).':'.$this->slug($key), $concept, $disposition, $owner, $representation,
            $file === self::META ? 'ProviderSchemaDiscovery' : 'PTDListingProperty', $shape, $read, $write,
            'not_applicable',
            'not_applicable', $evidence, $questions === [] ? 'not_applicable' : implode('|', array_map(fn ($q) => 'queue:'.$q, $questions)),
            $questions === [] ? 'PROVIDER_VERIFIED' : 'DEFERRED_REVIEW', 'Amazon provider-local schema evidence; no final cross-platform equivalence or runtime capability asserted.',
        ]);
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
            $row = $rows[0];
            $result[] = array_combine(BigCommerceCoverage::CONCEPT_HEADER, [
                $key, str_replace('_', ' ', $row['external_key']), 'Amazon provider-local, applicability-bound PTD semantic.', $row['entity_level'], $row['value_shape'], count($rows) > 1 ? 'repeated_role' : 'optional_one',
                'PTD_defined_shape', 'amazon_ptd_schema_evidence', $row['read_semantics'], $row['write_semantics'], 'product_type_marketplace_requirements_schema_version', $row['owner_candidate'], $row['representation_candidate'],
                'provider_schema_localization', 'provider_controlled', match ($row['disposition']) {
                    'REUSABLE_SEMANTIC' => 'reusable_candidate', 'DOMAIN_CAPABILITY' => 'domain', 'CHANNEL_SEMANTIC' => 'channel', 'APPLICABILITY_METADATA', 'TRANSPORT_MECHANIC' => 'governance', default => 'deferred'
                },
                'amazon', (string) count($rows), 'not_applicable', 'not_applicable', 'not_applicable', $row['decision_reference'], 'supported', $row['review_status'], 'Provider concept only; final synthesis deferred.',
            ]);
        }

        return $result;
    }

    private function buildDisagreements(array $coverage): array
    {
        $result = [];
        foreach ($this->disagreementDefinitions() as $key => [$family, $question]) {
            $rows = array_values(array_filter($coverage, fn ($row) => str_contains($row['decision_reference'], 'queue:'.$key)));
            $concepts = array_values(array_unique(array_column($rows, 'concept_key')));
            sort($concepts);
            $result[] = array_combine(BigCommerceCoverage::DISAGREEMENT_HEADER, [$key, $family, $question, implode('|', $concepts), (string) count($rows), 'provider-pass:cross-platform-review', 'OPEN', 'Amazon PTD evidence retained; portable ownership or representation remains deferred.']);
        }

        return $result;
    }

    private function validateDisagreements(array $coverage, array $concepts, array $questions, array &$errors): void
    {
        $seen = [];
        foreach ($questions as $q) {
            $seen[$q['question_key']] = true;
            $keys = array_filter(explode('|', $q['evidence_concept_keys']));
            foreach ($keys as $key) {
                if (! isset($concepts[$key])) {
                    $errors[] = "invalid Amazon disagreement $key";
                }
            }
            $rows = array_filter($coverage, fn ($row) => str_contains($row['decision_reference'], 'queue:'.$q['question_key']));
            if ((int) $q['affected_coverage_count'] !== count($rows)) {
                $errors[] = 'stale Amazon disagreement '.$q['question_key'];
            }
            foreach ($rows as $row) {
                if ($row['review_status'] !== 'DEFERRED_REVIEW') {
                    $errors[] = 'Amazon disagreement silently verified '.$q['question_key'];
                }
            }
        }
        foreach ($this->disagreementDefinitions() as $key => $_) {
            if (! isset($seen[$key])) {
                $errors[] = "missing Amazon disagreement $key";
            }
        }
    }

    private function semanticMetrics(array $coverage): array
    {
        $meta = array_filter($coverage, fn ($r) => $r['source_file'] === self::META);
        $rows = array_column(array_filter($coverage, fn ($r) => $r['source_file'] === self::LUGGAGE), null, 'external_key');
        $productTypeAttributes = ['department', 'outer', 'fabric_type', 'lining_description', 'number_of_wheels', 'wheel', 'size_map'];

        return [
            'invented_applicability_key_count' => count(array_filter($coverage, fn ($r) => $r['applicability_key'] !== 'not_applicable')),
            'invalid_condition_semantics_count' => count(array_filter([
                $rows['condition_type']['disposition'] !== 'REUSABLE_SEMANTIC',
                $rows['condition_type']['owner_candidate'] !== 'ProductVariantData',
                $rows['condition_type']['representation_candidate'] !== 'product_condition_enum',
                $rows['condition_note']['disposition'] !== 'CHANNEL_SEMANTIC',
                $rows['condition_note']['owner_candidate'] !== 'ListingCondition',
                $rows['condition_note']['representation_candidate'] !== 'amazon_listing_condition_note',
                $rows['condition_type']['concept_key'] === $rows['condition_note']['concept_key'],
            ])),
            'product_type_attribute_overpromotion_count' => count(array_filter($productTypeAttributes, fn ($key) => $rows[$key]['disposition'] !== 'CATEGORY_ATTRIBUTE'
                || $rows[$key]['owner_candidate'] !== 'ProductTypeAttribute'
                || $rows[$key]['representation_candidate'] !== 'luggage_ptd_scoped_attribute')),
            'meta_model_promoted_to_product_semantic_count' => count(array_filter($meta, fn ($r) => in_array($r['owner_candidate'], ['ProductData', 'ProductVariantData'], true) || $r['disposition'] === 'REUSABLE_SEMANTIC')),
            'ptd_property_promoted_to_unconditional_read_count' => count(array_filter($rows, fn ($r) => $r['read_semantics'] !== 'ptd_schema_presence_not_live_listing_read')),
            'ptd_property_promoted_to_unconditional_write_count' => count(array_filter($rows, fn ($r) => $r['write_semantics'] !== 'ptd_conditioned_write_not_unconditional')),
            'invalid_amazon_taxonomy_authority_count' => count(array_filter(['item_type_keyword', 'item_type_name'], fn ($k) => $rows[$k]['owner_candidate'] !== 'ConnectorTaxonomy')),
            'invalid_identifier_identity_classification_count' => count(array_filter([$rows['externally_assigned_product_identifier']['owner_candidate'] !== 'ProductVariantData', $rows['merchant_suggested_asin']['disposition'] === 'REUSABLE_SEMANTIC'])),
            'invalid_variant_relationship_owner_count' => count(array_filter(['parentage_level', 'child_parent_sku_relationship', 'variation_theme'], fn ($k) => $rows[$k]['owner_candidate'] !== 'VariantComposition')),
            'invalid_offer_domain_owner_count' => count(array_filter([$rows['purchasable_offer']['owner_candidate'] !== 'Pricing', $rows['fulfillment_channel_availability']['owner_candidate'] !== 'Availability'])),
            'invalid_item_package_semantic_merge_count' => count(array_filter([$rows['item_dimensions']['concept_key'] === $rows['item_package_dimensions']['concept_key'], $rows['item_dimensions']['concept_key'] === $rows['item_package_weight']['concept_key']])),
        ];
    }

    private function metrics(array $coverage, int $concepts, int $disagreements): array
    {
        $files = array_count_values(array_column($coverage, 'source_file'));

        return ['ptd_meta_model_rows' => $files[self::META] ?? 0, 'luggage_ptd_rows' => $files[self::LUGGAGE] ?? 0, 'coverage_rows' => count($coverage), 'concepts' => $concepts, 'disagreements' => $disagreements,
            'coverage_ratio' => (float) (count($coverage) / 101), 'classification_ratio' => (float) (count(array_filter($coverage, fn ($r) => $r['disposition'] !== '')) / count($coverage)), 'concept_link_ratio' => (float) (count(array_filter($coverage, fn ($r) => $r['concept_key'] !== '')) / count($coverage)),
            'silent_drop_count' => 101 - count($coverage), 'dispositions' => array_count_values(array_column($coverage, 'disposition')), 'owners' => array_count_values(array_column($coverage, 'owner_candidate'))];
    }

    private function luggageContext(array $row): string
    {
        return 'product_type='.$row['product_type'].';marketplace='.$row['marketplace'].';requirements='.$row['requirements'].';property_group='.$row['property_group'].';parentage='.$row['parentage_level'].';schema_version_token='.$row['schema_version_token'].';classification='.$row['classification'];
    }

    private function assertSources(string $root, array $meta, array $luggage): void
    {
        if (count($meta) !== 23 || count($luggage) !== 78 || hash_file('sha256', "$root/".self::META) !== self::META_HASH || hash_file('sha256', "$root/".self::LUGGAGE) !== self::LUGGAGE_HASH) {
            throw new RuntimeException('Amazon frozen source drift');
        }
        $models = array_count_values(array_column($meta, 'model'));
        if ($models !== ['ProductTypeDefinition' => 10, 'ProductTypeVersion' => 3, 'SchemaLink' => 2, 'PropertyGroup' => 3, 'ProductType' => 3, 'ProductTypeList' => 2]) {
            throw new RuntimeException('Amazon PTD meta-model distribution drift');
        }
        foreach ($luggage as $row) {
            if ($row['product_type'] !== 'LUGGAGE' || $row['marketplace'] !== 'ATVPDKIKX0DER' || $row['requirements'] !== 'LISTING' || $row['parentage_level'] !== 'NONE example' || $row['schema_version_token'] !== 'U8L4z4Ud95N16tZlR7rsmbQ==' || $row['verified_at'] !== '2026-09-07') {
                throw new RuntimeException('Amazon LUGGAGE applicability drift');
            }
        }
    }

    private function manifestRow(string $root, string $file, string $basis): array
    {
        $bytes = file_get_contents("$root/$file");
        $hash = hash('sha256', $bytes);

        return ['snapshot_id' => 'amazon-v1-'.substr($hash, 0, 16), 'repository_commit' => self::BASE_COMMIT, 'platform' => 'amazon', 'source_file' => $file, 'file_sha256' => $hash, 'header_sha256' => $this->headerHash($bytes), 'row_count' => $file === self::META ? '23' : '78', 'schema_version_basis' => $basis, 'captured_at' => '2026-09-07'];
    }

    private function upsertManifest(string $root, array $providerRows): array
    {
        [$header, $rows] = $this->readCsv("$root/".self::MANIFEST);
        $accepted = array_values(array_filter($rows, fn ($row) => $row['platform'] !== 'amazon'));
        if ($header !== BigCommerceCoverage::MANIFEST_HEADER) {
            throw new RuntimeException('accepted pre-Amazon manifest contract drift');
        }
        $before = $accepted;
        $index = [];
        foreach ($rows as $row) {
            $index[$row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file']] = $row;
        }
        foreach ($this->requiredPreAmazonManifestKeys() as $requiredKey) {
            if (! isset($index[$requiredKey])) {
                throw new RuntimeException("accepted pre-Amazon manifest identity missing $requiredKey");
            }
        }
        foreach ($providerRows as $row) {
            $index[$row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file']] = $row;
        }
        ksort($index, SORT_STRING);
        $result = array_values($index);
        foreach ($before as $row) {
            if (! in_array($row, $result, true)) {
                throw new RuntimeException('accepted provider manifest row changed');
            }
        }

        return $result;
    }

    private function requiredPreAmazonManifestKeys(): array
    {
        return [
            'adobe_commerce'.BigCommerceCoverage::SEPARATOR.'docs/data/adobe_commerce_v1_alias_groups.csv',
            'adobe_commerce'.BigCommerceCoverage::SEPARATOR.'docs/data/adobe_commerce_v1_inventory_master.csv',
            'adobe_commerce'.BigCommerceCoverage::SEPARATOR.'docs/data/adobe_commerce_v1_structured_object_fields.csv',
            'bigcommerce'.BigCommerceCoverage::SEPARATOR.'docs/data/bigcommerce_v3_product_capability_inventory.csv',
            'google_merchant'.BigCommerceCoverage::SEPARATOR.'docs/data/google_merchant_products_v1_attribute_inventory.csv',
            'google_merchant'.BigCommerceCoverage::SEPARATOR.'docs/data/google_merchant_products_v1_product_input_inventory.csv',
        ];
    }

    private function shape(string $type): string
    {
        return str_starts_with($type, 'array') ? 'list' : (str_starts_with($type, 'ref:') || $type === 'object' ? 'structured' : ($type === 'boolean' ? 'boolean' : 'string'));
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
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, null, ',', '"', '');
        $rows = [];
        while (($v = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($head, $v);
        } fclose($h);

        return [$head, $rows];
    }

    private function writeCsv(string $path, array $header, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        } $h = fopen($path, 'wb');
        fputcsv($h, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, array_values($row), ',', '"', '');
        } fclose($h);
    }
}
