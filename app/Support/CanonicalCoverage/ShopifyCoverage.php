<?php

namespace App\Support\CanonicalCoverage;

use RuntimeException;

final class ShopifyCoverage
{
    public const BASE_COMMIT = '5be3ec03ec915e6831ad86777d5284aa083be206';

    public const MASTER = 'docs/data/shopify_v1_inventory_master.csv';

    public const STRUCTURED = 'docs/data/shopify_v1_structured_object_fields.csv';

    public const ALIASES = 'docs/data/shopify_v1_alias_groups.csv';

    public const TAXONOMY = 'docs/data/shopify_v1_taxonomy_attribute_inventory.csv';

    public const FRESHNESS = 'docs/data/shopify_v1_freshness_event_matrix.csv';

    public const VERSIONS = 'docs/data/shopify_v1_version_change_matrix.csv';

    public const CLUSTERS = 'docs/data/shopify_v1_capability_clusters.csv';

    public const SOURCES = 'docs/data/shopify_v1_inventory_source_matrix.csv';

    public const STANDARD_METAFIELDS = 'docs/data/shopify_v1_standard_metafield_definitions.csv';

    public const COVERAGE = 'docs/data/canonical-coverage/shopify.csv';

    public const CONCEPTS = 'docs/data/canonical-coverage/shopify-concepts.csv';

    public const DISAGREEMENTS = 'docs/data/canonical-coverage/shopify-disagreements.csv';

    public const MANIFEST = BigCommerceCoverage::MANIFEST;

    public const DENOMINATOR = 10376;

    /** @return array<string, mixed> */
    public function generate(string $root): array
    {
        $sources = $this->sourceRows($root);
        $this->assertDenominator($root, $sources);

        $manifestRows = [
            $this->manifestRow($root, self::MASTER, count($sources[self::MASTER]), 'Shopify Admin GraphQL 2026-07 provider inventory master'),
            $this->manifestRow($root, self::STRUCTURED, count($sources[self::STRUCTURED]), 'Shopify Admin GraphQL 2026-07 structured and input-object inventory'),
            $this->manifestRow($root, self::ALIASES, count($sources[self::ALIASES]), 'Shopify cross-surface alias and representation inventory'),
            $this->manifestRow($root, self::TAXONOMY, count($sources[self::TAXONOMY]), 'Shopify ProductTaxonomy 1.2.0 pinned attribute definitions'),
            $this->manifestRow($root, self::FRESHNESS, count($sources[self::FRESHNESS]), 'Shopify webhook and Events freshness hints'),
            $this->manifestRow($root, self::VERSIONS, count($sources[self::VERSIONS]), 'Shopify 2026-07 version and change boundaries'),
        ];
        $manifest = $this->upsertManifest($root, $manifestRows);
        $manifestByFile = array_column($manifest, null, 'source_file');

        $coverage = [];
        $targetLookup = [];

        foreach ($sources[self::MASTER] as $index => $row) {
            [$disposition, $owner, $representation] = $this->classifyMaster($row);
            $externalKey = $row['shopify_object'].'.'.$row['shopify_key_or_capability'];
            $concept = 'shopify:master:'.$this->slug($row['shopify_object']).':'.$this->slug($row['shopify_key_or_capability']);
            $questions = $this->questionsForMaster($row);
            $record = $this->coverageRow(
                $manifestByFile[self::MASTER]['snapshot_id'], self::MASTER, $index + 1, $row,
                $row['source_surface'], $row['shopify_object'], $externalKey, 'not_applicable',
                $this->masterContext($row), 'not_applicable',
                'shopify:atom:master:'.$this->slug($row['shopify_object']).':'.$this->slug($row['shopify_key_or_capability']),
                $concept, $disposition, $owner, $representation, $this->entityLevel($row['shopify_object']),
                $this->masterValueShape($row), $this->masterReadContract($row), $this->writeContract($row['write_semantics']),
                'not_applicable', 'not_applicable',
                'shopify-master:'.$row['shopify_object'].':'.$row['shopify_key_or_capability'],
                $questions, 'Frozen Shopify master classification; no cross-platform equivalence asserted.'
            );
            $coverage[] = $record;
            $this->indexTarget($targetLookup, $record, $row['source_surface'], [$externalKey, $row['shopify_key_or_capability']]);
        }

        foreach ($sources[self::STRUCTURED] as $index => $row) {
            $externalKey = $row['object_family'].'.'.$row['subfield'];
            $concept = 'shopify:structure:'.$this->slug($row['object_family']).':'.$this->slug($row['subfield']);
            $questions = $this->questionsForStructured($row);
            [$owner, $representation] = $this->classifyStructured($row);
            $record = $this->coverageRow(
                $manifestByFile[self::STRUCTURED]['snapshot_id'], self::STRUCTURED, $index + 1, $row,
                $row['source_surface'], $row['object_family'], $externalKey, $externalKey,
                $this->structuredContext($row), 'not_applicable',
                'shopify:atom:structure:'.$this->slug($row['object_family']).':'.$this->slug($row['subfield']),
                $concept, 'STRUCTURE_MEMBER', $owner, $representation, 'StructuredMember',
                $this->structuredValueShape($row), $this->structuredReadContract($row), $this->writeContract($row['write_semantics']),
                'not_applicable', 'not_applicable',
                'shopify-structured:'.$row['object_family'].':'.$row['subfield'],
                $questions, $row['semantic_note'].'; frozen nested source member, parent not invented.'
            );
            $coverage[] = $record;
            $this->indexTarget($targetLookup, $record, $row['source_surface'], [$externalKey, $this->studly($row['object_family']).'.'.$row['subfield'], $row['subfield']]);
        }

        foreach ($sources[self::TAXONOMY] as $index => $row) {
            $concept = 'shopify:taxonomy:'.$this->slug($row['handle']);
            $context = 'section='.$row['taxonomy_section'].';taxonomy_id='.($row['taxonomy_id'] === '' ? 'not_assigned' : $row['taxonomy_id'])
                .';friendly_id='.$row['friendly_id'].';controlled_value_count='.$row['controlled_value_count']
                .';values_from='.($row['values_from'] === '' ? 'not_applicable' : $row['values_from'])
                .';source_commit='.$row['source_commit'].';source_blob='.$row['source_blob'].';review='.$row['review_status'];
            $record = $this->coverageRow(
                $manifestByFile[self::TAXONOMY]['snapshot_id'], self::TAXONOMY, $index + 1, $row,
                'shopify_product_taxonomy', 'TaxonomyAttributeDefinition', $row['handle'], 'not_applicable', $context,
                'not_applicable', 'shopify:atom:taxonomy:'.$this->slug($row['handle']), $concept,
                'CATEGORY_ATTRIBUTE', 'ConnectorTaxonomy', 'shopify_taxonomy_attribute_definition',
                'CategoryScopedProductAttribute', ((int) $row['controlled_value_count'] > 0 || $row['values_from'] !== '') ? 'controlled_or_inherited_vocabulary' : 'taxonomy_attribute',
                'taxonomy_definition', 'category_assignment_requires_taxonomy_context', 'not_applicable', 'not_applicable',
                'shopify-taxonomy:'.$row['handle'], [],
                'Pinned Shopify taxonomy definition; category applicability is provider-owned and does not imply a platform FieldDefinition.'
            );
            $coverage[] = $record;
        }

        foreach ($sources[self::FRESHNESS] as $index => $row) {
            $concept = 'shopify:freshness:'.$this->slug($row['topic_enum']);
            $record = $this->coverageRow(
                $manifestByFile[self::FRESHNESS]['snapshot_id'], self::FRESHNESS, $index + 1, $row,
                $row['source_surface'], 'FreshnessEvent', $row['topic_enum'], 'not_applicable',
                'topic='.$row['topic'].';cluster='.$row['cluster'].';required_context='.$row['required_scope_or_context'].';caveat='.$row['critical_caveat'].';review='.$row['review_status'],
                'not_applicable', 'shopify:atom:freshness:'.$this->slug($row['topic_enum']), $concept,
                'TRANSPORT_MECHANIC', 'Connector', 'freshness_event_hint', 'ConnectorEvent', 'event',
                'event_hint_requires_authoritative_reread', 'not_business_state_write', 'not_applicable', 'not_applicable',
                'shopify-freshness:'.$row['topic_enum'], [], 'Frozen freshness hint; authoritative business state must be re-read.'
            );
            $coverage[] = $record;
            $this->indexTarget($targetLookup, $record, $row['source_surface'], [$row['topic_enum'], $row['topic']]);
        }

        foreach ($sources[self::VERSIONS] as $index => $row) {
            $concept = 'shopify:version:'.$this->slug($row['change_id']);
            $coverage[] = $this->coverageRow(
                $manifestByFile[self::VERSIONS]['snapshot_id'], self::VERSIONS, $index + 1, $row,
                'shopify_version_changelog', 'VersionChangeBoundary', $row['change_id'], 'not_applicable',
                'effective_scope='.$row['effective_scope'].';announced_date='.$row['announced_date'].';cluster='.$row['cluster'].';implication='.$row['connector_implication'].';review='.$row['review_status'],
                'not_applicable', 'shopify:atom:version:'.$this->slug($row['change_id']), $concept,
                'APPLICABILITY_METADATA', 'ConnectorSchema', 'version_change_boundary', 'ConnectorSchemaVersion', 'metadata',
                'version_change_metadata', 'not_product_write_capability', 'not_applicable', 'not_applicable',
                $row['source_url'], [], 'Frozen Shopify version boundary; affects connector applicability/certification rather than Product data.'
            );
        }

        $aliasTargetsByGroup = [];
        foreach ($sources[self::ALIASES] as $row) {
            $target = $this->resolveTarget($targetLookup, $row['source_surface'], $row['surface_key']);
            if ($target !== null) {
                $aliasTargetsByGroup[$row['alias_group']] ??= $target;
            }
        }
        foreach ($sources[self::ALIASES] as $index => $row) {
            $target = $this->resolveTarget($targetLookup, $row['source_surface'], $row['surface_key']) ?? ($aliasTargetsByGroup[$row['alias_group']] ?? null);
            if ($target === null) {
                throw new RuntimeException("No Shopify alias target for {$row['alias_group']}:{$row['source_surface']}:{$row['surface_key']}");
            }
            $concept = 'shopify:alias:'.$this->slug($row['alias_group']);
            $coverage[] = $this->coverageRow(
                $manifestByFile[self::ALIASES]['snapshot_id'], self::ALIASES, $index + 1, $row,
                $row['source_surface'], 'ProviderRepresentation', $row['surface_key'], 'alias_group.'.$row['alias_group'].'.'.$row['surface_key'],
                'alias_group='.$row['alias_group'].';identity_rule='.$row['identity_rule'].';review='.$row['review_status'],
                'not_applicable', 'shopify:atom:alias:'.$this->slug($row['alias_group']).':'.($index + 1), $concept,
                'ALIAS_REPRESENTATION', $target['owner_candidate'], 'alias_with_'.$this->aliasRuleKind($row['identity_rule']),
                'ProviderRepresentation', 'surface_defined', 'representation_evidence', 'representation_specific',
                'not_applicable', $target['coverage_id'], 'shopify-alias:'.$row['alias_group'].':'.$row['surface_key'], [],
                $row['semantic_concept'].'; '.$row['identity_rule']
            );
        }

        $concepts = $this->buildConcepts($coverage);
        $disagreements = $this->buildDisagreements($coverage);
        $this->writeCsv("$root/".self::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);
        $this->writeCsv("$root/".self::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);
        $this->writeCsv("$root/".self::CONCEPTS, BigCommerceCoverage::CONCEPT_HEADER, $concepts);
        $this->writeCsv("$root/".self::DISAGREEMENTS, BigCommerceCoverage::DISAGREEMENT_HEADER, $disagreements);

        return $this->metrics($coverage, count($concepts), count($disagreements));
    }

    /** @return array<string, mixed> */
    public function validate(string $root): array
    {
        $sources = $this->sourceRows($root);
        $this->assertDenominator($root, $sources);
        [$manifestHeader, $manifest] = $this->readCsv("$root/".self::MANIFEST);
        [$coverageHeader, $coverage] = $this->readCsv("$root/".self::COVERAGE);
        [$conceptHeader, $concepts] = $this->readCsv("$root/".self::CONCEPTS);
        [$disagreementHeader, $disagreements] = $this->readCsv("$root/".self::DISAGREEMENTS);
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
        foreach ($sources as $file => $rows) {
            $entry = $manifestIndex['shopify'.BigCommerceCoverage::SEPARATOR.$file] ?? null;
            if ($entry === null) {
                $errors[] = "Shopify manifest entry missing $file";

                continue;
            }
            $bytes = file_get_contents("$root/$file");
            if ($entry['file_sha256'] !== hash('sha256', $bytes) || $entry['header_sha256'] !== $this->headerHash($bytes) || (int) $entry['row_count'] !== count($rows)) {
                $errors[] = "Shopify manifest integrity mismatch $file";
            }
        }

        if (count($coverage) !== self::DENOMINATOR) {
            $errors[] = 'Shopify physical coverage mismatch';
        }
        $conceptIndex = array_column($concepts, null, 'concept_key');
        $coverageById = array_column($coverage, null, 'coverage_id');
        $seen = [];
        foreach ($coverage as $row) {
            $file = $row['source_file'];
            $ordinal = (int) $row['source_row_ordinal'];
            $source = $sources[$file][$ordinal - 1] ?? null;
            if ($source === null) {
                $errors[] = "unknown Shopify physical source $file#$ordinal";

                continue;
            }
            $physical = "$file#$ordinal";
            if (isset($seen[$physical])) {
                $errors[] = "duplicate Shopify physical coverage $physical";
            }
            $seen[$physical] = true;
            $entry = $manifestIndex['shopify'.BigCommerceCoverage::SEPARATOR.$file] ?? null;
            if ($entry === null) {
                continue;
            }
            $rowHash = $this->rowHash(array_values($source));
            $expectedId = hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$entry['snapshot_id'], $file, (string) $ordinal, $rowHash]));
            if ($row['source_row_sha256'] !== $rowHash || $row['coverage_id'] !== $expectedId) {
                $errors[] = "Shopify provenance mismatch $physical";
            }
            if (! in_array($row['disposition'], BigCommerceCoverage::DISPOSITIONS, true) || ! isset($conceptIndex[$row['concept_key']])) {
                $errors[] = "Shopify classification/concept mismatch $physical";
            }
            if ($row['applicability_key'] !== 'not_applicable') {
                $errors[] = "invented Shopify applicability FK $physical";
            }
            if ($file === self::STRUCTURED && $row['disposition'] !== 'STRUCTURE_MEMBER') {
                $errors[] = "Shopify structured fate mismatch $physical";
            }
            if ($file === self::TAXONOMY && ($row['disposition'] !== 'CATEGORY_ATTRIBUTE' || $row['owner_candidate'] !== 'ConnectorTaxonomy')) {
                $errors[] = "Shopify taxonomy fate mismatch $physical";
            }
            if ($file === self::FRESHNESS && ($row['disposition'] !== 'TRANSPORT_MECHANIC' || $row['read_semantics'] !== 'event_hint_requires_authoritative_reread')) {
                $errors[] = "Shopify freshness authority mismatch $physical";
            }
            if ($file === self::VERSIONS && ($row['disposition'] !== 'APPLICABILITY_METADATA' || $row['owner_candidate'] !== 'ConnectorSchema')) {
                $errors[] = "Shopify version fate mismatch $physical";
            }
            if ($file === self::ALIASES && ($row['disposition'] !== 'ALIAS_REPRESENTATION' || ! isset($coverageById[$row['alias_of_coverage_id']]))) {
                $errors[] = "Shopify alias target mismatch $physical";
            }
        }

        $conceptEvidenceCounts = array_count_values(array_column($coverage, 'concept_key'));
        foreach ($concepts as $concept) {
            $evidenceCount = $conceptEvidenceCounts[$concept['concept_key']] ?? 0;
            if ($evidenceCount === 0) {
                $errors[] = 'orphan Shopify concept '.$concept['concept_key'];

                continue;
            }
            if ((int) $concept['evidence_coverage_count'] !== $evidenceCount || $concept['evidence_platforms'] !== 'shopify') {
                $errors[] = 'Shopify concept evidence mismatch '.$concept['concept_key'];
            }
        }
        $this->validateDisagreements($coverage, $conceptIndex, $disagreements, $errors);
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $this->metrics($coverage, count($concepts), count($disagreements)) + [
            'invalid_alias_reference_count' => 0,
            'invented_applicability_key_count' => 0,
            'taxonomy_attributes_promoted_to_platform_fields' => 0,
            'freshness_hints_promoted_to_state_authority' => 0,
            'manifest_provider_rows_preserved' => 'PASS',
        ];
    }

    private function sourceRows(string $root): array
    {
        $files = [self::MASTER, self::STRUCTURED, self::ALIASES, self::TAXONOMY, self::FRESHNESS, self::VERSIONS];
        $result = [];
        foreach ($files as $file) {
            [, $result[$file]] = $this->readCsv("$root/$file");
        }

        return $result;
    }

    private function assertDenominator(string $root, array $sources): void
    {
        $expected = [self::MASTER => 738, self::STRUCTURED => 926, self::ALIASES => 106, self::TAXONOMY => 8556, self::FRESHNESS => 36, self::VERSIONS => 14];
        foreach ($expected as $file => $count) {
            if (count($sources[$file] ?? []) !== $count) {
                throw new RuntimeException("Shopify frozen source drift $file");
            }
        }
        if (array_sum($expected) !== self::DENOMINATOR) {
            throw new RuntimeException('Shopify denominator contract drift');
        }

        [, $clusters] = $this->readCsv("$root/".self::CLUSTERS);
        $master = $sources[self::MASTER];
        $clusterIndex = array_column($clusters, null, 'cluster');
        foreach ($master as $row) {
            if (! isset($clusterIndex[$row['cluster']])) {
                throw new RuntimeException('Unknown Shopify cluster '.$row['cluster']);
            }
        }
        foreach ($clusters as $cluster) {
            $actual = count(array_filter($master, fn ($row) => $row['cluster'] === $cluster['cluster']));
            if ((int) $cluster['inventory_count'] !== $actual) {
                throw new RuntimeException('Shopify cluster count drift '.$cluster['cluster']);
            }
        }

        [, $sourceMatrix] = $this->readCsv("$root/".self::SOURCES);
        $knownSurfaces = array_fill_keys(array_column($sourceMatrix, 'surface'), true);
        foreach (array_merge($sources[self::MASTER], $sources[self::STRUCTURED]) as $row) {
            if (! isset($knownSurfaces[$row['source_surface']])) {
                throw new RuntimeException('Unknown Shopify source surface '.$row['source_surface']);
            }
        }

        [, $standard] = $this->readCsv("$root/".self::STANDARD_METAFIELDS);
        if (count($standard) !== 15) {
            throw new RuntimeException('Shopify standard metafield supporting corpus drift');
        }
        $masterStandardKeys = array_fill_keys(array_map(fn ($row) => $row['shopify_key_or_capability'], array_filter($master, fn ($row) => $row['shopify_object'] === 'StandardMetafieldDefinition')), true);
        foreach ($standard as $row) {
            if (! isset($masterStandardKeys[$row['namespace_key']])) {
                throw new RuntimeException('Shopify standard metafield missing from master '.$row['namespace_key']);
            }
        }

        $taxonomy = $sources[self::TAXONOMY];
        if (count(array_unique(array_column($taxonomy, 'handle'))) !== 8556 || array_sum(array_map('intval', array_column($taxonomy, 'controlled_value_count'))) !== 74820) {
            throw new RuntimeException('Shopify taxonomy normalized inventory drift');
        }
        $sections = array_count_values(array_column($taxonomy, 'taxonomy_section'));
        if (($sections['base_attributes'] ?? 0) !== 8240 || ($sections['extended_attributes'] ?? 0) !== 316) {
            throw new RuntimeException('Shopify taxonomy section distribution drift');
        }
        foreach ($taxonomy as $row) {
            if ($row['source_commit'] !== 'ad206247ecc45a95fe4b01bce2ad2f0e7bec3c66' || $row['source_blob'] !== '455818cf3a5ae41f1db23c244adf1b5694691b88') {
                throw new RuntimeException('Shopify taxonomy pin drift');
            }
        }
        if (count(array_unique(array_column($sources[self::FRESHNESS], 'topic_enum'))) !== 36 || count(array_unique(array_column($sources[self::VERSIONS], 'change_id'))) !== 14) {
            throw new RuntimeException('Shopify freshness/version identity drift');
        }
    }

    private function classifyMaster(array $row): array
    {
        $objectKey = $row['shopify_object'].'.'.$row['shopify_key_or_capability'];
        if ($row['source_surface'] === 'product_csv') {
            return ['TRANSPORT_MECHANIC', 'ConnectorTransport', 'bulk_csv_field_representation'];
        }
        if ($objectKey === 'Product.vendor') {
            return ['CHANNEL_SEMANTIC', 'Connector', 'shopify_vendor_label'];
        }
        if ($objectKey === 'StandardMetafieldDefinition.descriptors.subtitle') {
            return ['DEFER_DECISION', 'FieldDefinitionOrContent', 'shopify_standard_subtitle_candidate'];
        }
        if ($this->isUnitPricingKey($objectKey)) {
            return ['DEFER_DECISION', 'PricingOrCompliance', 'structured_unit_pricing_measure'];
        }

        return match ($row['entry_kind']) {
            'semantic_field' => $this->classifySemanticField($row),
            'domain_value' => ['DOMAIN_CAPABILITY', $this->owner($row['platform_owner_candidate']), 'domain_value'],
            'structured_capability' => ['DOMAIN_CAPABILITY', $this->owner($row['platform_owner_candidate']), 'structured_capability'],
            'connector_context' => ['CHANNEL_SEMANTIC', 'Connector', 'provider_channel_context'],
            'schema_metadata' => ['APPLICABILITY_METADATA', 'ConnectorSchema', 'provider_schema_metadata'],
            'derived_projection' => ['DERIVED_PROJECTION', $this->owner($row['platform_owner_candidate']), 'read_projection'],
            'external_system_metadata' => $this->classifyExternalMetadata($row),
            default => throw new RuntimeException('Unknown Shopify master entry_kind '.$row['entry_kind']),
        };
    }

    private function classifySemanticField(array $row): array
    {
        return match ($row['cluster']) {
            'product_content', 'merchant_classification', 'product_identity_lifecycle', 'shipping_customs', 'variant_identity' => ['REUSABLE_SEMANTIC', $this->owner($row['platform_owner_candidate']), 'provider_semantic_field'],
            'collections_relations' => ['DOMAIN_CAPABILITY', 'Category', 'category_domain_value'],
            'dynamic_metafields' => ['DOMAIN_CAPABILITY', 'DynamicField', 'dynamic_field_value_container'],
            'gift_card_product' => ['DOMAIN_CAPABILITY', 'GiftCard', 'gift_card_domain_value'],
            'markets_localization' => ['DOMAIN_CAPABILITY', 'Localization', 'localized_domain_value'],
            'media' => ['DOMAIN_CAPABILITY', 'Media', 'media_domain_value'],
            'selling_plans_purchase_options' => ['DOMAIN_CAPABILITY', 'PurchaseOptions', 'purchase_option_domain_value'],
            default => throw new RuntimeException('Unreviewed Shopify semantic_field cluster '.$row['cluster'].':'.$row['shopify_object'].'.'.$row['shopify_key_or_capability']),
        };
    }

    private function classifyExternalMetadata(array $row): array
    {
        $key = strtolower($row['shopify_key_or_capability']);
        $representation = strtolower($row['representation_candidate']);
        $object = $row['shopify_object'];
        if ($object === 'ProductSetOperation' || $object === 'WebhookSubscription' || $row['cluster'] === 'connector_bulk_transport') {
            return ['TRANSPORT_MECHANIC', 'Connector', $object === 'WebhookSubscription' ? 'connector_subscription_identity' : 'async_transport_identity'];
        }
        if (str_contains($key, 'cursor') || str_contains($representation, 'cursor')) {
            return ['TRANSPORT_MECHANIC', 'Connector', 'pagination_or_transport_metadata'];
        }
        if ($key === 'id' || str_contains($key, 'legacyresourceid') || str_contains($representation, ' gid') || str_ends_with($representation, 'gid') || str_contains($representation, 'identifier')) {
            $kind = match (true) {
                in_array($object, ['Product', 'ProductVariant', 'InventoryItem'], true) => 'external_record_identity',
                in_array($object, ['ProductSync', 'ProductSetIdentifiers', 'GiftCardProductVariantSetInput'], true) => 'external_record_selector',
                str_contains($object, 'Taxonomy') => 'taxonomy_object_identity',
                default => 'provider_object_identity',
            };

            return ['EXTERNAL_IDENTITY', 'Connector', $kind];
        }
        if (str_contains($representation, ' reference') || str_ends_with($representation, 'reference') || str_contains($representation, ' identity')) {
            return ['EXTERNAL_IDENTITY', 'Connector', 'provider_object_reference'];
        }

        return ['DERIVED_PROJECTION', 'Connector', 'remote_system_metadata'];
    }

    private function classifyStructured(array $row): array
    {
        $surface = $row['source_surface'];
        $owner = match (true) {
            str_contains($surface, 'pricelist'), str_contains($surface, 'catalogs_markets') => 'Pricing',
            str_contains($surface, 'inventory') => 'Inventory',
            str_contains($surface, 'media'), str_contains($surface, 'files') => 'Media',
            str_contains($surface, 'options'), str_contains($surface, 'variant') => 'VariantComposition',
            str_contains($surface, 'collections') => 'Category',
            str_contains($surface, 'bundles'), str_contains($surface, 'combined_listings') => 'ProductComposition',
            str_contains($surface, 'selling_plans') => 'PurchaseOptions',
            str_contains($surface, 'delivery_profiles') => 'Fulfillment',
            str_contains($surface, 'metafields'), str_contains($surface, 'metaobjects') => 'DynamicSchema',
            str_contains($surface, 'taxonomy') => 'ConnectorTaxonomy',
            str_contains($surface, 'publication'), str_contains($surface, 'product_feeds') => 'Connector',
            default => $row['entry_kind'] === 'semantic_field' ? 'FieldDefinitionCandidate' : 'ProviderCapability',
        };

        return [$owner, 'structured_'.$this->slug($row['entry_kind'])];
    }

    private function questionsForMaster(array $row): array
    {
        $key = $row['shopify_object'].'.'.$row['shopify_key_or_capability'];
        $result = [];
        if ($key === 'StandardMetafieldDefinition.descriptors.subtitle') {
            $result[] = 'shopify_short_title_ownership';
        }
        if ($this->isUnitPricingKey($key)) {
            $result[] = 'shopify_unit_pricing_ownership';
        }

        return $result;
    }

    private function questionsForStructured(array $row): array
    {
        $key = $row['object_family'].'.'.$row['subfield'];

        return $this->isUnitPricingKey($key) ? ['shopify_unit_pricing_ownership'] : [];
    }

    private function isUnitPricingKey(string $key): bool
    {
        $value = strtolower($key);

        return str_contains($value, 'unitpricemeasurement') || str_contains($value, 'unit_price_measurement') || str_contains($value, 'unitprice');
    }

    private function disagreementDefinitions(): array
    {
        return [
            'shopify_short_title_ownership' => ['content_ownership', 'Does Shopify standard subtitle map to a FieldDefinition or a Content-domain concept, and how is localization represented?'],
            'shopify_unit_pricing_ownership' => ['unit_pricing', 'What portable Pricing/Compliance ownership and structured measure contract should represent Shopify unit-pricing semantics?'],
        ];
    }

    private function buildDisagreements(array $coverage): array
    {
        $definitions = $this->disagreementDefinitions();
        $result = [];
        foreach ($definitions as $key => [$family, $question]) {
            $rows = array_values(array_filter($coverage, fn ($row) => in_array('queue:'.$key, $this->list($row['decision_reference']), true)));
            if ($rows === []) {
                continue;
            }
            $concepts = array_values(array_unique(array_column($rows, 'concept_key')));
            sort($concepts, SORT_STRING);
            $result[] = array_combine(BigCommerceCoverage::DISAGREEMENT_HEADER, [
                $key, $family, $question, implode('|', $concepts), (string) count($rows),
                'cross-platform-synthesis:deferred', 'OPEN', 'Provider fate is preserved; platform ownership remains deferred.',
            ]);
        }

        return $result;
    }

    private function validateDisagreements(array $coverage, array $conceptIndex, array $disagreements, array &$errors): void
    {
        $definitions = $this->disagreementDefinitions();
        $seen = [];
        foreach ($disagreements as $row) {
            $key = $row['question_key'];
            if (! isset($definitions[$key]) || isset($seen[$key])) {
                $errors[] = "invalid/duplicate Shopify disagreement $key";

                continue;
            }
            $seen[$key] = true;
            $concepts = $this->list($row['evidence_concept_keys']);
            foreach ($concepts as $concept) {
                if (! isset($conceptIndex[$concept])) {
                    $errors[] = "invalid Shopify disagreement concept $concept";
                }
            }
            $affected = array_values(array_filter($coverage, fn ($candidate) => in_array($candidate['concept_key'], $concepts, true) && in_array('queue:'.$key, $this->list($candidate['decision_reference']), true)));
            if ((int) $row['affected_coverage_count'] !== count($affected)) {
                $errors[] = "stale Shopify disagreement $key";
            }
            foreach ($affected as $candidate) {
                if ($candidate['review_status'] !== 'DEFERRED_REVIEW') {
                    $errors[] = "Shopify disagreement silently verified $key";
                }
            }
        }
        foreach ($definitions as $key => $_) {
            $expected = count(array_filter($coverage, fn ($row) => in_array('queue:'.$key, $this->list($row['decision_reference']), true)));
            if ($expected > 0 && ! isset($seen[$key])) {
                $errors[] = "missing Shopify disagreement $key";
            }
        }
    }

    private function coverageRow(string $snapshot, string $file, int $ordinal, array $source, string $surface, string $family, string $externalKey, string $structuredPath, string $context, string $parentId, string $atom, string $concept, string $disposition, string $owner, string $representation, string $entityLevel, string $valueShape, string $read, string $write, string $applicability, string $aliasOf, string $evidence, array $questions, string $note): array
    {
        $rowHash = $this->rowHash(array_values($source));
        $decision = $questions === [] ? 'not_applicable' : implode('|', array_map(fn ($question) => 'queue:'.$question, $questions));
        $review = $questions === [] ? 'PROVIDER_VERIFIED' : 'DEFERRED_REVIEW';

        return array_combine(BigCommerceCoverage::COVERAGE_HEADER, [
            hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$snapshot, $file, (string) $ordinal, $rowHash])),
            $snapshot, 'shopify', $file, (string) $ordinal, $rowHash, '2026-07-or-pinned-supporting-version',
            $surface, $family, $externalKey, $structuredPath, $context, $parentId, $atom, $concept,
            $disposition, $owner, $representation, $entityLevel, $valueShape, $read, $write,
            $applicability, $aliasOf, $evidence, $decision, $review, $note,
        ]);
    }

    private function buildConcepts(array $coverage): array
    {
        $groups = [];
        foreach ($coverage as $row) {
            $groups[$row['concept_key']][] = $row;
        }
        ksort($groups, SORT_STRING);
        $result = [];
        foreach ($groups as $key => $rows) {
            $first = $rows[0];
            $owners = array_values(array_unique(array_column($rows, 'owner_candidate')));
            $representations = array_values(array_unique(array_column($rows, 'representation_candidate')));
            $entities = array_values(array_unique(array_column($rows, 'entity_level')));
            $reads = array_values(array_unique(array_column($rows, 'read_semantics')));
            $writes = array_values(array_unique(array_column($rows, 'write_semantics')));
            $decisions = [];
            foreach ($rows as $row) {
                $decisions = array_merge($decisions, $this->list($row['decision_reference']));
            }
            $decisions = array_values(array_unique($decisions));
            sort($decisions, SORT_STRING);
            $deferred = count(array_filter($rows, fn ($row) => $row['review_status'] === 'DEFERRED_REVIEW')) > 0;
            $result[] = array_combine(BigCommerceCoverage::CONCEPT_HEADER, [
                $key, $first['external_key'], 'Shopify provider-local concept; cross-platform equivalence is not asserted by Gate 1E.',
                count($entities) === 1 ? $entities[0] : 'mixed_provider_representation',
                count(array_unique(array_column($rows, 'value_shape'))) === 1 ? $first['value_shape'] : 'provider_defined',
                'provider_defined', 'not_applicable', $this->conceptSourceKind($first['disposition']),
                count($reads) === 1 ? $reads[0] : 'mixed_provider_contract', count($writes) === 1 ? $writes[0] : 'mixed_provider_contract',
                $first['disposition'] === 'CATEGORY_ATTRIBUTE' ? 'shopify_taxonomy_context' : ($first['disposition'] === 'APPLICABILITY_METADATA' ? 'api_version_context' : 'not_applicable'),
                count($owners) === 1 ? $owners[0] : 'mixed_reviewed_owner', count($representations) === 1 ? $representations[0] : 'multiple_provider_representations',
                'not_applicable', 'provider_defined', $this->conceptStatus($first['disposition']), 'shopify', (string) count($rows),
                'not_applicable', 'not_applicable', 'not_applicable', $decisions === [] ? 'not_applicable' : implode('|', $decisions),
                'supported', $deferred ? 'DEFERRED_REVIEW' : 'PROVIDER_VERIFIED', 'Evidence remains provider-local and provenance-backed.',
            ]);
        }

        return $result;
    }

    private function conceptSourceKind(string $disposition): string
    {
        return match ($disposition) {
            'REUSABLE_SEMANTIC' => 'reusable_candidate',
            'CATEGORY_ATTRIBUTE' => 'provider_taxonomy',
            'DOMAIN_CAPABILITY' => 'domain',
            'CHANNEL_SEMANTIC' => 'channel',
            'EXTERNAL_IDENTITY' => 'external_identity',
            'APPLICABILITY_METADATA' => 'governance',
            'STRUCTURE_MEMBER' => 'structure',
            'DERIVED_PROJECTION' => 'projection',
            'TRANSPORT_MECHANIC' => 'transport',
            'ALIAS_REPRESENTATION' => 'representation',
            default => 'deferred',
        };
    }

    private function conceptStatus(string $disposition): string
    {
        return match ($disposition) {
            'REUSABLE_SEMANTIC' => 'reusable_candidate',
            'CATEGORY_ATTRIBUTE' => 'category_candidate',
            'DOMAIN_CAPABILITY' => 'domain',
            'CHANNEL_SEMANTIC' => 'channel',
            'EXTERNAL_IDENTITY', 'APPLICABILITY_METADATA' => 'governance',
            'STRUCTURE_MEMBER' => 'structure',
            'DERIVED_PROJECTION' => 'projection',
            'TRANSPORT_MECHANIC' => 'transport',
            'ALIAS_REPRESENTATION' => 'representation',
            default => 'deferred',
        };
    }

    private function masterContext(array $row): string
    {
        return 'entry_kind='.$row['entry_kind'].';cluster='.$row['cluster'].';api_version_scope='.$row['api_version_scope']
            .';raw_read='.$row['read_semantics'].';raw_write='.$row['write_semantics'].';review='.$row['review_status'];
    }

    private function structuredContext(array $row): string
    {
        return 'entry_kind='.$row['entry_kind'].';raw_write='.$row['write_semantics'].';review='.$row['review_status'];
    }

    private function masterReadContract(array $row): string
    {
        return match ($row['entry_kind']) {
            'derived_projection' => 'derived_read_projection',
            'schema_metadata' => 'schema_metadata_read',
            'external_system_metadata' => str_contains(strtolower($row['representation_candidate']), 'cursor') ? 'transport_metadata_read' : 'remote_metadata_read',
            default => str_contains(strtoupper($row['read_semantics']), 'READ') || str_contains(strtoupper($row['read_semantics']), 'EXPORT') ? 'provider_read_surface' : 'read_not_proven',
        };
    }

    private function structuredReadContract(array $row): string
    {
        return $row['entry_kind'] === 'derived_projection' ? 'derived_read_projection' : 'schema_member_read_evidence';
    }

    private function writeContract(string $value): string
    {
        $v = strtolower($value);
        if (str_contains($v, 'read-only') || str_contains($v, 'read only') || str_contains($v, 'system-owned') || str_contains($v, 'system owned') || str_contains($v, 'derived') || str_contains($v, 'no direct')) {
            return 'read_only';
        }
        if (str_contains($v, 'write') || str_contains($v, 'writable') || str_contains($v, 'import') || str_contains($v, 'mutation') || str_contains($v, 'create') || str_contains($v, 'update') || str_contains($v, 'set')) {
            return 'provider_declared_write_not_certified';
        }

        return 'not_proven';
    }

    private function masterValueShape(array $row): string
    {
        return match ($row['entry_kind']) {
            'structured_capability' => 'structured',
            'domain_value' => 'provider_typed_domain_value',
            'schema_metadata' => 'schema_metadata',
            'derived_projection' => 'projection',
            'connector_context' => 'context',
            'external_system_metadata' => 'metadata',
            default => 'provider_typed_semantic',
        };
    }

    private function structuredValueShape(array $row): string
    {
        return $row['entry_kind'] === 'structured_capability' ? 'structured' : 'provider_typed_member';
    }

    private function entityLevel(string $object): string
    {
        return match (true) {
            $object === 'Product' => 'Product',
            $object === 'ProductVariant' => 'ProductVariant',
            str_contains($object, 'Inventory') => 'Inventory',
            str_contains($object, 'ProductOption') => 'VariantComposition',
            str_contains($object, 'Collection') => 'Category',
            str_contains($object, 'Price') || str_contains($object, 'Catalog') => 'Pricing',
            str_contains($object, 'Media') || str_contains($object, 'File') => 'Media',
            default => 'ProviderContext',
        };
    }

    private function owner(string $value): string
    {
        $value = trim($value);

        return match (true) {
            $value === '' => 'UnresolvedProviderOwner',
            str_contains($value, 'ProductVariant') => 'ProductVariantData',
            str_contains($value, 'Product fields') || str_contains($value, 'Product classification') || str_contains($value, 'Product fields/classification') => 'ProductData',
            str_contains($value, 'Product lifecycle') => 'ProductLifecycle',
            str_contains($value, 'Product logistics') => 'ProductLogistics',
            str_contains($value, 'Bulk import/export') => 'ConnectorTransport',
            str_contains($value, 'Category') => 'Category',
            str_contains($value, 'Localization') => 'Localization',
            str_contains($value, 'Media') => 'Media',
            str_contains($value, 'Pricing') => 'Pricing',
            str_contains($value, 'Inventory') => 'Inventory',
            str_contains($value, 'Fulfillment') => 'Fulfillment',
            str_contains($value, 'Purchase options') => 'PurchaseOptions',
            str_contains($value, 'Gift-card') || str_contains($value, 'gift-card') => 'GiftCard',
            str_contains($value, 'Field Dictionary') => 'DynamicField',
            default => preg_replace('/\s+/', '', str_replace(['/', '&'], ['Or', 'And'], $value)),
        };
    }

    private function indexTarget(array &$lookup, array $record, string $surface, array $keys): void
    {
        foreach ($keys as $key) {
            $lookup[$surface.BigCommerceCoverage::SEPARATOR.$key] ??= $record;
        }
    }

    private function resolveTarget(array $lookup, string $surface, string $key): ?array
    {
        $direct = $lookup[$surface.BigCommerceCoverage::SEPARATOR.$key] ?? null;
        if ($direct !== null) {
            return $direct;
        }
        if (preg_match('/^(.+)\.([A-Za-z0-9_]+(?:\/[A-Za-z0-9_]+)+)$/', $key, $matches) !== 1) {
            return null;
        }
        $prefix = $matches[1];
        $members = explode('/', $matches[2]);
        $resolved = [];
        foreach ($members as $member) {
            $candidate = $lookup[$surface.BigCommerceCoverage::SEPARATOR.$prefix.'.'.$member] ?? null;
            if ($candidate === null) {
                return null;
            }
            $resolved[] = $candidate;
        }

        return $resolved[0] ?? null;
    }

    private function aliasRuleKind(string $rule): string
    {
        $v = strtolower($rule);

        return match (true) {
            str_contains($v, 'delete') || str_contains($v, 'recreate') || str_contains($v, 'destructive') || str_contains($v, 'replace') => 'destructive_or_replacement_transform',
            str_contains($v, 'derived') || str_contains($v, 'projection') => 'derived_projection',
            str_contains($v, 'hint') || str_contains($v, 'freshness') => 'freshness_hint',
            str_contains($v, 'same') => 'explicit_same_semantic',
            default => 'documented_transform',
        };
    }

    private function metrics(array $coverage, int $conceptCount, int $disagreementCount): array
    {
        $byFile = array_count_values(array_column($coverage, 'source_file'));

        return [
            'master_rows' => $byFile[self::MASTER] ?? 0,
            'structured_rows' => $byFile[self::STRUCTURED] ?? 0,
            'alias_rows' => $byFile[self::ALIASES] ?? 0,
            'taxonomy_rows' => $byFile[self::TAXONOMY] ?? 0,
            'freshness_rows' => $byFile[self::FRESHNESS] ?? 0,
            'version_rows' => $byFile[self::VERSIONS] ?? 0,
            'coverage_rows' => count($coverage),
            'concepts' => $conceptCount,
            'disagreements' => $disagreementCount,
            'coverage_ratio' => (float) (count($coverage) / self::DENOMINATOR),
            'classification_ratio' => (float) (count(array_filter($coverage, fn ($row) => $row['disposition'] !== '')) / max(1, count($coverage))),
            'concept_link_ratio' => (float) (count(array_filter($coverage, fn ($row) => $row['concept_key'] !== '')) / max(1, count($coverage))),
            'terminal_rationale_ratio' => 1.0,
            'silent_drop_count' => self::DENOMINATOR - count($coverage),
            'dispositions' => array_count_values(array_column($coverage, 'disposition')),
        ];
    }

    private function manifestRow(string $root, string $file, int $count, string $basis): array
    {
        $bytes = file_get_contents("$root/$file");
        $hash = hash('sha256', $bytes);

        return [
            'snapshot_id' => 'shopify-v1-'.substr($hash, 0, 16),
            'repository_commit' => self::BASE_COMMIT,
            'platform' => 'shopify',
            'source_file' => $file,
            'file_sha256' => $hash,
            'header_sha256' => $this->headerHash($bytes),
            'row_count' => (string) $count,
            'schema_version_basis' => $basis,
            'captured_at' => '2026-09-07',
        ];
    }

    private function upsertManifest(string $root, array $providerRows): array
    {
        [$header, $rows] = $this->readCsv("$root/".self::MANIFEST);
        if ($header !== BigCommerceCoverage::MANIFEST_HEADER) {
            throw new RuntimeException('manifest contract mismatch');
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

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function list(string $value): array
    {
        return $value === '' || $value === 'not_applicable' ? [] : explode('|', $value);
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
        if ($handle === false) {
            throw new RuntimeException("Cannot write $path");
        }
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);
    }
}
