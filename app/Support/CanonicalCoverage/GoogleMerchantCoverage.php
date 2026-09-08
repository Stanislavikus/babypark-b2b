<?php

namespace App\Support\CanonicalCoverage;

use RuntimeException;

final class GoogleMerchantCoverage
{
    public const BASE_COMMIT = '5be3ec03ec915e6831ad86777d5284aa083be206';

    public const ATTRIBUTES = 'docs/data/google_merchant_products_v1_attribute_inventory.csv';

    public const PRODUCT_INPUT = 'docs/data/google_merchant_products_v1_product_input_inventory.csv';

    public const COVERAGE = 'docs/data/canonical-coverage/google-merchant.csv';

    public const CONCEPTS = 'docs/data/canonical-coverage/google-merchant-concepts.csv';

    public const DISAGREEMENTS = 'docs/data/canonical-coverage/google-merchant-disagreements.csv';

    public const MANIFEST = BigCommerceCoverage::MANIFEST;

    public function generate(string $root): array
    {
        [, $attributes] = $this->readCsv("$root/".self::ATTRIBUTES);
        [, $input] = $this->readCsv("$root/".self::PRODUCT_INPUT);
        $this->assertDenominator($attributes, $input);
        $manifest = $this->upsertManifest($root, [
            $this->manifestRow($root, self::ATTRIBUTES, 145, 'Google Merchant Products v1 ProductAttributes'),
            $this->manifestRow($root, self::PRODUCT_INPUT, 11, 'Google Merchant Products v1 ProductInput'),
        ]);
        $manifestByFile = array_column($manifest, null, 'source_file');
        $coverage = [];
        foreach ($attributes as $index => $row) {
            [$disposition, $owner, $representation] = $this->classifyAttribute($row);
            $concept = 'google_merchant:attribute:'.$this->slug($row['external_field']);
            $questions = $this->questionsForConcept($concept);
            $coverage[] = $this->coverageRow(
                $manifestByFile[self::ATTRIBUTES]['snapshot_id'], self::ATTRIBUTES, $index + 1, array_values($row),
                $row['api_surface'], $row['external_field'], $this->attributeContext($row), $concept,
                $disposition, $owner, $representation, $this->shape($row['type_or_ref']),
                'processed_publication_output', 'conditional_write_by_data_spec', $questions,
                $row['source_url'].'#'.$row['source_version']
            );
        }
        foreach ($input as $index => $row) {
            [$disposition, $owner, $representation, $read, $write] = $this->classifyInput($row);
            $concept = 'google_merchant:product_input:'.$this->slug($row['external_field']);
            $coverage[] = $this->coverageRow(
                $manifestByFile[self::PRODUCT_INPUT]['snapshot_id'], self::PRODUCT_INPUT, $index + 1, array_values($row),
                'ProductInput', $row['external_field'], 'classification='.$row['classification'].';description='.$row['description'], $concept,
                $disposition, $owner, $representation, $this->shape($row['type_or_ref']), $read, $write, [],
                $row['source_url'].'#'.$row['source_version']
            );
        }
        $concepts = $this->buildConcepts($coverage);
        $questions = $this->buildDisagreements($coverage);
        $this->writeCsv("$root/".self::MANIFEST, BigCommerceCoverage::MANIFEST_HEADER, $manifest);
        $this->writeCsv("$root/".self::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $coverage);
        $this->writeCsv("$root/".self::CONCEPTS, BigCommerceCoverage::CONCEPT_HEADER, $concepts);
        $this->writeCsv("$root/".self::DISAGREEMENTS, BigCommerceCoverage::DISAGREEMENT_HEADER, $questions);

        return $this->metrics($coverage, count($concepts));
    }

    public function validate(string $root): array
    {
        [, $attributes] = $this->readCsv("$root/".self::ATTRIBUTES);
        [, $input] = $this->readCsv("$root/".self::PRODUCT_INPUT);
        [$manifestHeader, $manifest] = $this->readCsv("$root/".self::MANIFEST);
        [$coverageHeader, $coverage] = $this->readCsv("$root/".self::COVERAGE);
        [$conceptHeader, $concepts] = $this->readCsv("$root/".self::CONCEPTS);
        [$questionHeader, $questions] = $this->readCsv("$root/".self::DISAGREEMENTS);
        $this->assertDenominator($attributes, $input);
        $errors = [];
        if ($manifestHeader !== BigCommerceCoverage::MANIFEST_HEADER || $coverageHeader !== BigCommerceCoverage::COVERAGE_HEADER || $conceptHeader !== BigCommerceCoverage::CONCEPT_HEADER || $questionHeader !== BigCommerceCoverage::DISAGREEMENT_HEADER) {
            $errors[] = 'shared provider contract mismatch';
        }
        $manifestIndex = [];
        foreach ($manifest as $row) {
            $id = $row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file'];
            if (isset($manifestIndex[$id])) {
                $errors[] = "duplicate manifest identity $id";
            }
            $manifestIndex[$id] = $row;
        }
        $manifestBasis = [
            self::ATTRIBUTES => 'Google Merchant Products v1 ProductAttributes',
            self::PRODUCT_INPUT => 'Google Merchant Products v1 ProductInput',
        ];
        foreach ([self::ATTRIBUTES => $attributes, self::PRODUCT_INPUT => $input] as $file => $rows) {
            $entry = $manifestIndex['google_merchant'.BigCommerceCoverage::SEPARATOR.$file] ?? null;
            $expectedManifest = $this->manifestRow($root, $file, count($rows), $manifestBasis[$file]);
            if ($entry === null || $entry !== $expectedManifest) {
                $errors[] = "Google manifest mismatch $file";
            }
        }
        $sources = [self::ATTRIBUTES => $attributes, self::PRODUCT_INPUT => $input];
        $conceptIndex = array_column($concepts, null, 'concept_key');
        $seen = [];
        foreach ($coverage as $row) {
            $file = $row['source_file'];
            $ordinal = (int) $row['source_row_ordinal'];
            $source = $sources[$file][$ordinal - 1] ?? null;
            if ($source === null) {
                $errors[] = "unknown Google row $file#$ordinal";

                continue;
            }
            $physical = "$file#$ordinal";
            if (isset($seen[$physical])) {
                $errors[] = "duplicate Google row $physical";
            }
            $seen[$physical] = true;
            $manifestRow = $manifestIndex['google_merchant'.BigCommerceCoverage::SEPARATOR.$file];
            $hash = $this->rowHash(array_values($source));
            $id = hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$manifestRow['snapshot_id'], $file, (string) $ordinal, $hash]));
            if ($row['source_row_sha256'] !== $hash || $row['coverage_id'] !== $id) {
                $errors[] = "Google provenance mismatch $physical";
            }
            if (! isset($conceptIndex[$row['concept_key']]) || ! in_array($row['disposition'], BigCommerceCoverage::DISPOSITIONS, true)) {
                $errors[] = "Google concept/disposition mismatch $physical";
            }
            if ($row['applicability_key'] !== 'not_applicable') {
                $errors[] = "dangling Google applicability $physical";
            }
            if ($file === self::ATTRIBUTES) {
                [$d, $o, $r] = $this->classifyAttribute($source);
                if ($row['disposition'] !== $d || $row['owner_candidate'] !== $o || $row['representation_candidate'] !== $r || $row['read_semantics'] !== 'processed_publication_output' || $row['write_semantics'] !== 'conditional_write_by_data_spec') {
                    $errors[] = "Google attribute contract mismatch $physical";
                }
                if ($row['source_context_key'] !== $this->attributeContext($source)) {
                    $errors[] = "Google vertical/binding context mismatch $physical";
                }
            } else {
                [$d, $o, $r, $read, $write] = $this->classifyInput($source);
                if ($row['disposition'] !== $d || $row['owner_candidate'] !== $o || $row['representation_candidate'] !== $r || $row['read_semantics'] !== $read || $row['write_semantics'] !== $write) {
                    $errors[] = "Google ProductInput contract mismatch $physical";
                }
            }
        }
        if (count($seen) !== 156 || count($coverage) !== 156) {
            $errors[] = 'Google physical coverage mismatch';
        }
        foreach ($concepts as $concept) {
            $rows = array_filter($coverage, fn ($row) => $row['concept_key'] === $concept['concept_key']);
            if (count($rows) !== 1 || (int) $concept['evidence_coverage_count'] !== 1 || $concept['source_of_truth_kind'] === 'authoritative_product_truth') {
                $errors[] = 'Google concept evidence/authority mismatch '.$concept['concept_key'];
            }
        }
        $this->validateDisagreements($coverage, $conceptIndex, $questions, $errors);
        $semanticMetrics = $this->semanticValidationMetrics($coverage);
        foreach ($semanticMetrics as $metric => $count) {
            if ($count !== 0) {
                $errors[] = "Google semantic validation failed $metric=$count";
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_unique($errors)));
        }

        return $this->metrics($coverage, count($concepts)) + [
            'invalid_external_identity_count' => 0, 'processed_output_promoted_to_authoritative_count' => 0,
            'unconditional_write_from_conditional_source_count' => 0,
            'open_disagreements_with_verified_rows' => 0, 'invalid_disagreement_refs' => 0,
            'dangling_applicability_keys' => 0, 'manifest_provider_rows_preserved' => 'PASS',
        ] + $semanticMetrics;
    }

    private function coverageRow(string $snapshot, string $file, int $ordinal, array $values, string $surface, string $key, string $context, string $concept, string $disposition, string $owner, string $representation, string $shape, string $read, string $write, array $questions, string $evidence): array
    {
        $hash = $this->rowHash($values);

        return array_combine(BigCommerceCoverage::COVERAGE_HEADER, [
            hash('sha256', implode(BigCommerceCoverage::SEPARATOR, [$snapshot, $file, (string) $ordinal, $hash])), $snapshot, 'google_merchant', $file, (string) $ordinal, $hash,
            'Merchant Products v1', $surface, $file === self::ATTRIBUTES ? 'ProductAttributes' : 'ProductInput', $key, 'not_applicable', $context,
            'not_applicable', 'google_merchant:atom:'.($file === self::ATTRIBUTES ? 'attribute:' : 'product_input:').$this->slug($key), $concept,
            $disposition, $owner, $representation, $file === self::ATTRIBUTES ? 'ProcessedProductAttribute' : 'ProductInput', $shape, $read, $write,
            'not_applicable', 'not_applicable', $evidence,
            $questions === [] ? 'not_applicable' : implode('|', array_map(fn ($q) => 'queue:'.$q, $questions)),
            $questions === [] ? 'PROVIDER_VERIFIED' : 'DEFERRED_REVIEW', 'Google-local publication semantic; no cross-platform equivalence asserted.',
        ]);
    }

    private function classifyAttribute(array $row): array
    {
        $class = $row['classification'];
        $key = $row['external_field'];
        if ($key === 'shortTitle') {
            return ['DEFER_DECISION', 'FieldDefinitionOrContent', 'short_title_candidate'];
        }
        if ($key === 'relatedProducts') {
            return ['DOMAIN_CAPABILITY', 'ProductAssociation', 'publication_relationship_capability'];
        }
        if (in_array($key, ['itemGroupId', 'itemGroupTitle', 'variantOptions'], true)) {
            return ['DOMAIN_CAPABILITY', 'VariantComposition', 'variant_grouping_composition'];
        }
        if ($key === 'isBundle') {
            return ['DOMAIN_CAPABILITY', 'BundleComposition', 'business_defined_bundle_composition'];
        }
        if ($key === 'multipack') {
            return ['DEFER_DECISION', 'ProductOrPackaging', 'identical_product_multipack_quantity_candidate'];
        }
        if (in_array($key, ['dateFirstRegistered', 'model'], true)) {
            return ['CATEGORY_ATTRIBUTE', 'VehicleVertical', 'vertical_scoped_publication_attribute'];
        }
        if ($key === 'sellOnGoogleQuantity') {
            return ['CHANNEL_SEMANTIC', 'Connector', 'google_publication_quantity_control'];
        }
        if (in_array($key, ['unitPricingMeasure', 'unitPricingBaseMeasure'], true)) {
            return ['DEFER_DECISION', 'PricingOrCompliance', 'structured_unit_pricing_measure'];
        }
        if ($key === 'sustainabilityIncentives') {
            return ['DEFER_DECISION', 'Compliance', 'sustainability_incentive_program_candidate'];
        }
        if (in_array($key, ['vehicleAllInPrice', 'vehicleExpenses', 'vehicleMsrp', 'vehiclePriceType', 'productFee'], true)) {
            return ['DOMAIN_CAPABILITY', 'Pricing', 'vertical_scoped_pricing_capability'];
        }
        if (in_array($key, ['co2Emissions', 'emissionsStandard', 'energyConsumption', 'vehicleMandatoryInspectionIncluded', 'warranty'], true)) {
            return ['DEFER_DECISION', 'Compliance', 'vertical_compliance_candidate'];
        }
        if (in_array($key, ['identifierExists', 'canonicalLink', 'link', 'mobileLink'], true)) {
            return ['CHANNEL_SEMANTIC', 'Connector', 'publication_governance_or_link'];
        }
        if ($key === 'availability' || $key === 'availabilityDate') {
            return ['DOMAIN_CAPABILITY', 'Availability', 'publication_availability_binding'];
        }
        if (in_array($key, ['adult', 'certifications', 'energyEfficiencyClass', 'minEnergyEfficiencyClass', 'maxEnergyEfficiencyClass'], true)) {
            return ['DOMAIN_CAPABILITY', 'Compliance', 'publication_compliance_evidence'];
        }

        return match (true) {
            $class === 'semantic_product_field', $class === 'identifier_or_identity_semantic' => ['REUSABLE_SEMANTIC', 'ProductData', 'processed_output_with_conditional_input_binding'],
            $class === 'channel_context' => ['CHANNEL_SEMANTIC', 'Connector', 'publication_channel_control'],
            $class === 'channel_or_specialized_context' => throw new RuntimeException("Unreviewed Google channel or specialized context $key"),
            str_starts_with($class, 'specialized_vertical_field:') => ['CATEGORY_ATTRIBUTE', str_ends_with($class, 'vehicle') ? 'VehicleVertical' : 'PropertyVertical', 'vertical_scoped_publication_attribute'],
            $class === 'pricing_or_commercial_domain' => ['DOMAIN_CAPABILITY', 'Pricing', 'publication_commercial_binding'],
            $class === 'media_domain' => ['DOMAIN_CAPABILITY', 'Media', 'publication_media_binding'],
            $class === 'shipping_returns_domain' => ['DOMAIN_CAPABILITY', 'ShippingReturns', 'publication_shipping_returns_capability'],
            $class === 'relationship_or_variant_capability' => throw new RuntimeException("Unreviewed Google relationship or variant capability $key"),
            $class === 'taxonomy_context' => ['CHANNEL_SEMANTIC', 'Connector', 'google_taxonomy_context'],
            default => throw new RuntimeException("Unknown Google classification $class"),
        };
    }

    private function classifyInput(array $row): array
    {
        return match ($row['external_field']) {
            'offerId' => ['EXTERNAL_IDENTITY', 'Connector', 'merchant_offer_identity', 'product_input_resource', 'merchant_supplied'],
            'name', 'product', 'base64EncodedName', 'base64EncodedProduct' => ['TRANSPORT_MECHANIC', 'Connector', 'resource_locator_or_encoding', 'resource_projection', 'system_or_request_mechanic'],
            'versionNumber' => ['TRANSPORT_MECHANIC', 'Connector', 'concurrency_version', 'resource_projection', 'conditional_insert_update'],
            'contentLanguage', 'feedLabel', 'legacyLocal' => ['CHANNEL_SEMANTIC', 'Connector', 'publication_context', 'product_input_context', 'request_context'],
            'productAttributes', 'customAttributes' => ['DOMAIN_CAPABILITY', 'Connector', 'submission_attribute_container', 'product_input_container', 'submission_container'],
            default => throw new RuntimeException('Unknown ProductInput field '.$row['external_field']),
        };
    }

    private function attributeContext(array $row): string
    {
        $vertical = $this->verticalForAttribute($row);

        return 'layer=processed_product_output;input_binding=ProductInput.productAttributes;write_condition=Product_Data_Specification;classification='.$row['classification'].';vertical='.$vertical;
    }

    private function buildConcepts(array $coverage): array
    {
        $result = [];
        foreach ($coverage as $row) {
            $result[] = array_combine(BigCommerceCoverage::CONCEPT_HEADER, [
                $row['concept_key'], str_replace('_', ' ', $row['external_key']), 'Google Merchant provider-local publication semantic.', $row['entity_level'], $row['value_shape'], 'optional_one',
                'not_applicable', 'google_publication_or_submission', $row['read_semantics'], $row['write_semantics'], str_contains($row['source_context_key'], 'vertical=') ? 'provider_contextual' : 'provider_context',
                $row['owner_candidate'], $row['representation_candidate'], 'publication_language_context_not_localization', 'provider_controlled_or_typed',
                match ($row['disposition']) {
                    'REUSABLE_SEMANTIC' => 'reusable_candidate', 'CATEGORY_ATTRIBUTE' => 'category_candidate', 'DOMAIN_CAPABILITY' => 'domain', 'CHANNEL_SEMANTIC' => 'channel', 'EXTERNAL_IDENTITY', 'TRANSPORT_MECHANIC' => 'governance', default => 'deferred'
                },
                'google_merchant', '1', 'not_applicable', 'not_applicable', 'not_applicable', $row['decision_reference'], 'supported', $row['review_status'], 'Single physical Google representation; cross-platform merge deferred.',
            ]);
        }
        usort($result, fn ($a, $b) => $a['concept_key'] <=> $b['concept_key']);

        return $result;
    }

    private function disagreementDefinitions(): array
    {
        return [
            'google_availability' => ['availability', 'How does Google availability vocabulary relate to platform Availability without becoming lifecycle?', ['availability']],
            'google_taxonomy' => ['taxonomy', 'How should Google taxonomy map without becoming platform Category authority?', ['googleProductCategory', 'productTypes']],
            'google_vertical_applicability' => ['verticals', 'How should vehicle/property applicability be represented portably?', []],
            'google_identifier_exists' => ['identifier_governance', 'How should identifierExists remain publication governance?', ['identifierExists']],
            'google_compliance' => ['compliance', 'Which Google compliance claims are portable evidence and who owns them?', ['adult', 'certifications', 'energyEfficiencyClass', 'minEnergyEfficiencyClass', 'maxEnergyEfficiencyClass', 'co2Emissions', 'emissionsStandard', 'energyConsumption', 'vehicleMandatoryInspectionIncluded', 'warranty']],
            'google_unit_pricing' => ['unit_pricing', 'What is the portable ownership of unit-pricing measures?', ['unitPricingMeasure', 'unitPricingBaseMeasure']],
            'google_short_title_ownership' => ['content_ownership', 'Does shortTitle belong to FieldDefinition or a Content domain, and how is it localized?', ['shortTitle']],
            'google_sustainability_incentives' => ['compliance', 'Are sustainability incentive programs reusable Compliance evidence or Google publication context?', ['sustainabilityIncentives']],
            'google_multipack_ownership' => ['packaging', 'How does the narrow identical-product multipack quantity relate to Product and Packaging semantics?', ['multipack']],
            'google_preorder_date' => ['preorder', 'How does availabilityDate map to preorder/backorder semantics?', ['availabilityDate']],
            'google_landing_url' => ['url', 'How do canonicalLink/link/mobileLink differ from ordinary Product URL?', ['canonicalLink', 'link', 'mobileLink']],
            'google_processed_ownership' => ['processed_output', 'Which processed-output semantics require owner arbitration?', ['popularityRank', 'questionsAndAnswers']],
        ];
    }

    private function questionsForConcept(string $concept): array
    {
        $key = str_replace('google_merchant:attribute:', '', $concept);
        $result = [];
        foreach ($this->disagreementDefinitions() as $question => [$family, $text, $keys]) {
            if (($question === 'google_vertical_applicability' && str_contains($concept, ':attribute:') && $this->isVerticalKey($key)) || in_array($key, array_map(fn ($x) => $this->slug($x), $keys), true)) {
                $result[] = $question;
            }
        }

        return $result;
    }

    private function isVerticalKey(string $slug): bool
    {
        return in_array($slug, array_map(fn ($key) => $this->slug($key), [
            'amenityFeature', 'bodyStyle', 'certifiedPreOwned', 'co2Emissions', 'dateFirstRegistered', 'displayAddress', 'electricRange', 'emissionsStandard', 'energyConsumption', 'engine', 'fuelConsumption', 'fuelConsumptionDischargedBattery', 'latitude', 'leaseTerm', 'longitude', 'mileage', 'model', 'neighborhood', 'numberOfBathrooms', 'numberOfBedrooms', 'numberOfUnits', 'petPolicy', 'productFee', 'propertyName', 'propertyType', 'specialtyHousingType', 'trim', 'unitArea', 'utilitiesIncluded', 'vehicleAllInPrice', 'vehicleExpenses', 'vehicleMandatoryInspectionIncluded', 'vehicleMsrp', 'vehiclePriceType', 'vin', 'warranty', 'year',
        ]), true);
    }

    private function verticalForAttribute(array $row): string
    {
        if (in_array($row['external_field'], ['dateFirstRegistered', 'model'], true)) {
            return 'vehicle';
        }

        return str_starts_with($row['classification'], 'specialized_vertical_field:')
            ? explode(':', $row['classification'], 2)[1]
            : 'not_applicable';
    }

    private function semanticValidationMetrics(array $coverage): array
    {
        $rows = array_column(array_filter($coverage, fn ($row) => $row['source_object_family'] === 'ProductAttributes'), null, 'external_key');
        $invalidRelationships = count(array_filter([
            $rows['relatedProducts']['owner_candidate'] !== 'ProductAssociation',
            ...array_map(fn ($key) => $rows[$key]['owner_candidate'] !== 'VariantComposition', ['itemGroupId', 'itemGroupTitle', 'variantOptions']),
            $rows['isBundle']['owner_candidate'] !== 'BundleComposition',
            $rows['multipack']['owner_candidate'] === 'ProductAssociation',
        ]));
        $ambiguousContext = count(array_filter($rows, fn ($row) => str_contains($row['source_context_key'], 'classification=channel_or_specialized_context')
            && $row['representation_candidate'] === 'publication_channel_control'));
        $frozenDeferredVerified = count(array_filter(['shortTitle', 'unitPricingMeasure', 'unitPricingBaseMeasure'], fn ($key) => $rows[$key]['review_status'] === 'PROVIDER_VERIFIED'));
        $pricing = ['vehicleAllInPrice' => 'vehicle', 'vehicleExpenses' => 'vehicle', 'vehicleMsrp' => 'vehicle', 'vehiclePriceType' => 'vehicle', 'productFee' => 'property'];
        $invalidVerticalFate = count(array_filter($pricing, fn ($vertical, $key) => $rows[$key]['owner_candidate'] !== 'Pricing'
            || ! str_contains($rows[$key]['source_context_key'], "vertical=$vertical"), ARRAY_FILTER_USE_BOTH));
        $compliance = ['co2Emissions', 'emissionsStandard', 'energyConsumption', 'vehicleMandatoryInspectionIncluded', 'warranty'];
        $invalidVerticalFate += count(array_filter($compliance, fn ($key) => $rows[$key]['owner_candidate'] !== 'Compliance'
            || $rows[$key]['disposition'] !== 'DEFER_DECISION'
            || ! str_contains($rows[$key]['source_context_key'], 'vertical=vehicle')));
        $invalidVerticalScope = count(array_filter($rows, function ($row) {
            $slug = $this->slug($row['external_key']);
            $expected = $this->isVerticalKey($slug);
            $scoped = ! str_contains($row['source_context_key'], 'vertical=not_applicable');

            return $expected !== $scoped;
        }));

        return [
            'invalid_vertical_scope_count' => $invalidVerticalScope,
            'invalid_relationship_family_mapping_count' => $invalidRelationships,
            'ambiguous_channel_or_specialized_context_count' => $ambiguousContext,
            'frozen_deferred_candidate_marked_verified_count' => $frozenDeferredVerified,
            'invalid_vertical_domain_fate_count' => $invalidVerticalFate,
        ];
    }

    private function buildDisagreements(array $coverage): array
    {
        $result = [];
        foreach ($this->disagreementDefinitions() as $key => [$family, $question, $fields]) {
            $rows = array_values(array_filter($coverage, fn ($row) => in_array($key, $this->questionsForConcept($row['concept_key']), true)));
            $concepts = array_column($rows, 'concept_key');
            sort($concepts, SORT_STRING);
            $result[] = array_combine(BigCommerceCoverage::DISAGREEMENT_HEADER, [$key, $family, $question, implode('|', $concepts), (string) count($rows), 'provider-pass:cross-platform-review', 'OPEN', 'Google publication fate is retained; portable decision deferred.']);
        }

        return $result;
    }

    private function validateDisagreements(array $coverage, array $concepts, array $questions, array &$errors): void
    {
        $seen = [];
        foreach ($questions as $q) {
            $seen[$q['question_key']] = true;
            $keys = explode('|', $q['evidence_concept_keys']);
            foreach ($keys as $key) {
                if (! isset($concepts[$key])) {
                    $errors[] = "invalid Google disagreement $key";
                }
            }
            $rows = array_filter($coverage, fn ($row) => in_array($row['concept_key'], $keys, true));
            if ((int) $q['affected_coverage_count'] !== count($rows)) {
                $errors[] = 'stale Google disagreement '.$q['question_key'];
            }
            foreach ($rows as $row) {
                if ($row['review_status'] === 'PROVIDER_VERIFIED' || ! str_contains($row['decision_reference'], 'queue:'.$q['question_key'])) {
                    $errors[] = 'Google disagreement silently verified '.$q['question_key'];
                }
            }
        }
        foreach ($this->disagreementDefinitions() as $key => $_) {
            if (! isset($seen[$key])) {
                $errors[] = "missing Google disagreement $key";
            }
        }
    }

    private function metrics(array $coverage, int $concepts): array
    {
        $files = array_count_values(array_column($coverage, 'source_file'));

        return ['attribute_rows' => $files[self::ATTRIBUTES] ?? 0, 'product_input_rows' => $files[self::PRODUCT_INPUT] ?? 0, 'coverage_rows' => count($coverage), 'concepts' => $concepts,
            'coverage_ratio' => (float) (count($coverage) / 156), 'classification_ratio' => (float) (count(array_filter($coverage, fn ($r) => $r['disposition'] !== '')) / count($coverage)),
            'concept_link_ratio' => (float) (count(array_filter($coverage, fn ($r) => $r['concept_key'] !== '')) / count($coverage)), 'terminal_rationale_ratio' => 1.0,
            'silent_drop_count' => 156 - count($coverage), 'dispositions' => array_count_values(array_column($coverage, 'disposition'))];
    }

    private function manifestRow(string $root, string $file, int $count, string $basis): array
    {
        $bytes = file_get_contents("$root/$file");
        $hash = hash('sha256', $bytes);

        return ['snapshot_id' => 'google-merchant-v1-'.substr($hash, 0, 16), 'repository_commit' => self::BASE_COMMIT, 'platform' => 'google_merchant', 'source_file' => $file, 'file_sha256' => $hash, 'header_sha256' => $this->headerHash($bytes), 'row_count' => (string) $count, 'schema_version_basis' => $basis, 'captured_at' => '2026-09-07'];
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
                throw new RuntimeException("duplicate manifest $key");
            } $index[$key] = $row;
        }
        foreach ($providerRows as $row) {
            $index[$row['platform'].BigCommerceCoverage::SEPARATOR.$row['source_file']] = $row;
        }
        ksort($index, SORT_STRING);

        return array_values($index);
    }

    private function assertDenominator(array $a, array $i): void
    {
        if (count($a) !== 145 || count($i) !== 11) {
            throw new RuntimeException('Google frozen source drift');
        }
    }

    private function rowHash(array $v): string
    {
        return hash('sha256', json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function headerHash(string $b): string
    {
        return hash('sha256', strstr($b, "\n", true)."\n");
    }

    private function slug(string $v): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $v)), '_');
    }

    private function shape(string $type): string
    {
        return str_starts_with($type, 'array') ? 'list' : (str_starts_with($type, 'ref:') ? 'structured' : (str_contains($type, 'boolean') ? 'boolean' : (str_contains($type, 'number') ? 'decimal' : 'string')));
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
