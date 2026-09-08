<?php

namespace Tests\Unit\CanonicalCoverage;

use App\Support\CanonicalCoverage\AdobeCommerceCoverage;
use App\Support\CanonicalCoverage\BigCommerceCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AdobeCommerceCoverageTest extends TestCase
{
    #[Test]
    public function committed_adobe_slice_has_complete_validated_coverage(): void
    {
        $metrics = (new AdobeCommerceCoverage)->validate(dirname(__DIR__, 3));

        $this->assertSame(184, $metrics['master_rows']);
        $this->assertSame(189, $metrics['structured_rows']);
        $this->assertSame(51, $metrics['alias_rows']);
        $this->assertSame(424, $metrics['coverage_rows']);
        $this->assertSame(360, $metrics['concepts']);
        $this->assertEquals(1.0, $metrics['coverage_ratio']);
        $this->assertEquals(1.0, $metrics['classification_ratio']);
        $this->assertEquals(1.0, $metrics['concept_link_ratio']);
        $this->assertSame(0, $metrics['silent_drop_count']);
        $this->assertSame(0, $metrics['orphan_structured_parent_count']);
        $this->assertSame(0, $metrics['invalid_alias_reference_count']);
    }

    #[Test]
    public function manifest_contains_all_three_adobe_hashes_and_bigcommerce(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = array_column($this->readCsv("$root/".AdobeCommerceCoverage::MANIFEST), null, 'source_file');

        $this->assertGreaterThanOrEqual(4, count($manifest));
        foreach ([AdobeCommerceCoverage::MASTER, AdobeCommerceCoverage::STRUCTURED, AdobeCommerceCoverage::ALIASES] as $file) {
            $this->assertSame(hash_file('sha256', "$root/$file"), $manifest[$file]['file_sha256']);
        }
        $this->assertArrayHasKey(BigCommerceCoverage::SOURCE, $manifest);
    }

    #[Test]
    public function generation_and_cross_provider_round_trip_are_byte_deterministic(): void
    {
        $root = dirname(__DIR__, 3);
        $adobe = new AdobeCommerceCoverage;
        $bigCommerce = new BigCommerceCoverage;
        $files = [AdobeCommerceCoverage::MANIFEST, AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS, BigCommerceCoverage::COVERAGE, BigCommerceCoverage::CONCEPTS, BigCommerceCoverage::DISAGREEMENTS];
        $before = $this->hashes($root, $files);

        $adobe->generate($root);
        $bigCommerce->generate($root);
        $adobe->validate($root);
        $bigCommerce->validate($root);

        $this->assertSame($before, $this->hashes($root, $files));
    }

    #[Test]
    public function structured_members_have_valid_parents_and_nested_identifiers_stay_members(): void
    {
        $rows = $this->coverage(dirname(__DIR__, 3));
        $ids = array_column($rows, null, 'coverage_id');
        $structured = array_filter($rows, fn ($row) => $row['source_file'] === AdobeCommerceCoverage::STRUCTURED);

        $this->assertCount(189, $structured);
        foreach ($structured as $row) {
            $this->assertSame('STRUCTURE_MEMBER', $row['disposition']);
            $this->assertArrayHasKey($row['parent_coverage_id'], $ids);
        }
        foreach (array_filter($structured, fn ($row) => in_array($row['external_key'], ['id', 'uid', 'sku', 'code'], true)) as $anchor) {
            $this->assertNotSame('EXTERNAL_IDENTITY', $anchor['disposition']);
        }
    }

    #[Test]
    public function aliases_preserve_targets_and_non_raw_equal_transformations(): void
    {
        $rows = $this->coverage(dirname(__DIR__, 3));
        $ids = array_column($rows, null, 'coverage_id');
        $aliases = array_filter($rows, fn ($row) => $row['source_file'] === AdobeCommerceCoverage::ALIASES);

        $this->assertCount(51, $aliases);
        foreach ($aliases as $alias) {
            $this->assertSame('ALIAS_REPRESENTATION', $alias['disposition']);
            $this->assertArrayHasKey($alias['alias_of_coverage_id'], $ids);
            $this->assertStringContainsString('identity_rule=', $alias['source_context_key']);
        }
        $taxName = $this->find($aliases, 'external_key', 'tax_class_name');
        $this->assertSame('alias_with_id_name_resolution', $taxName['representation_candidate']);
    }

    #[Test]
    public function critical_adobe_boundaries_remain_provider_local(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));

        $this->assertSame(['Connector', 'provider_scope_context'], $this->owner($master['visibility']));
        $this->assertSame('AttributeSchema', $master['attribute_sets']['owner_candidate']);
        $this->assertSame('Connector', $master['website_assignment']['owner_candidate']);
        $this->assertSame('Category', $master['categories']['owner_candidate']);
        $this->assertSame('ProductAssociation', $master['product_links']['owner_candidate']);
        $this->assertSame('VariantComposition', $master['configurable_product_options']['owner_candidate']);
        $this->assertSame('OrderCustomization', $master['custom_option_definition']['owner_candidate']);
        $this->assertSame('BundleComposition', $master['bundle_values']['owner_candidate']);
        $this->assertSame('GroupedComposition', $master['grouped_product_item']['owner_candidate']);
        $this->assertSame('DownloadableComposition', $master['downloadable_link']['owner_candidate']);
        $this->assertSame('SharedCatalog', $master['shared_catalog_product_membership']['owner_candidate']);
        $this->assertSame('GiftCard', $master['giftcard_amounts']['owner_candidate']);
    }

    #[Test]
    public function every_master_connector_context_is_connector_owned_channel_semantics(): void
    {
        $root = dirname(__DIR__, 3);
        $master = $this->readCsv($root.'/'.AdobeCommerceCoverage::MASTER);
        $coverage = $this->masterRows($root);
        $contexts = array_filter($master, fn ($row) => $row['entry_kind'] === 'connector_context');

        $this->assertCount(16, $contexts);
        foreach ($contexts as $source) {
            $row = $coverage[$source['adobe_key_or_capability']];
            $this->assertSame('CHANNEL_SEMANTIC', $row['disposition']);
            $this->assertSame('Connector', $row['owner_candidate']);
            $this->assertSame('provider_scope_context', $row['representation_candidate']);
        }
    }

    #[Test]
    public function media_import_slots_and_rest_role_tokens_are_explicit_non_raw_equal_representations(): void
    {
        $root = dirname(__DIR__, 3);
        $master = array_column($this->readCsv($root.'/'.AdobeCommerceCoverage::MASTER), null, 'adobe_key_or_capability');
        $aliases = array_values(array_filter(
            $this->readCsv($root.'/'.AdobeCommerceCoverage::ALIASES),
            fn ($row) => str_starts_with($row['alias_group'], 'media_'),
        ));

        foreach (['base_image', 'small_image', 'thumbnail_image', 'additional_images', 'additional_image_labels', 'hide_from_product_page'] as $key) {
            $this->assertSame('Import API', $master[$key]['source_surface']);
        }
        foreach (['base_image_label', 'small_image_label', 'thumbnail_image_label'] as $key) {
            $this->assertSame('Import API', $master[$key]['source_surface']);
        }

        $this->assertCount(12, $aliases);
        $this->assertSame(
            ['media_base_role', 'media_small_role', 'media_thumbnail_role', 'media_additional_gallery', 'media_additional_labels', 'media_hidden_from_product_page'],
            array_values(array_unique(array_column($aliases, 'alias_group'))),
        );
        foreach ($aliases as $alias) {
            $this->assertStringContainsString('not raw-equal', $alias['identity_rule']);
        }

        $structured = $this->structuredRows($root);
        $this->assertStringContainsString('additional_image_labels', $structured['media_gallery_entry:label']['review_note']);
        $this->assertStringContainsString('not a role-specific Import *_image_label field', $structured['media_gallery_entry:label']['review_note']);
        $this->assertStringContainsString('additional_images', $structured['media_gallery_entry:file']['review_note']);
        $this->assertStringContainsString('hide_from_product_page', $structured['media_gallery_entry:disabled']['review_note']);
        $this->assertStringContainsString('not raw-equal representations', $structured['media_gallery_entry:types']['review_note']);
        foreach (['base_image_label', 'small_image_label', 'thumbnail_image_label'] as $roleLabel) {
            $this->assertCount(0, array_filter($aliases, fn ($row) => $row['surface_key'] === $roleLabel));
        }
    }

    #[Test]
    public function classic_cost_and_catalog_pricing_cost_storage_remain_distinct_pricing_representations(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));

        foreach (['cost', 'cost_storage'] as $key) {
            $this->assertSame('DOMAIN_CAPABILITY', $master[$key]['disposition']);
            $this->assertSame('Pricing', $master[$key]['owner_candidate']);
        }
        $this->assertNotSame($master['cost']['concept_key'], $master['cost_storage']['concept_key']);
    }

    #[Test]
    public function pricing_inventory_media_and_catalog_projections_stay_separate(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));

        $this->assertNotSame($master['price']['concept_key'], $master['special_price']['concept_key']);
        $this->assertNotSame($master['map_price']['concept_key'], $master['msrp_price']['concept_key']);
        $this->assertSame('Inventory', $master['inventory_source']['owner_candidate']);
        $this->assertSame('Availability', $master['salable_quantity']['owner_candidate']);
        $this->assertSame('Media', $master['media_gallery_entries']['owner_candidate']);
        foreach ($master as $key => $row) {
            if (str_starts_with($key, 'catalog_service_')) {
                $this->assertSame('DERIVED_PROJECTION', $row['disposition']);
                $this->assertSame('read_only', $row['write_semantics']);
            }
        }
    }

    #[Test]
    public function rma_gift_wrap_and_other_open_questions_are_deferred_in_ledger(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));
        foreach (['rma_eligibility', 'is_returnable', 'gift_wrapping_capability', 'gift_wrapping_available', 'gift_wrapping_price'] as $key) {
            $this->assertSame('DEFERRED_REVIEW', $master[$key]['review_status']);
            $this->assertStringContainsString('queue:adobe_', $master[$key]['decision_reference']);
        }
        $this->assertSame('not_proven', $master['rma_eligibility']['write_semantics']);
        $this->assertSame('not_proven', $master['gift_wrapping_capability']['write_semantics']);
        $this->assertSame('read_only', $master['is_returnable']['write_semantics']);
        $this->assertSame('read_only', $master['gift_wrapping_available']['write_semantics']);
        $this->assertSame('not_proven', $master['gift_wrapping_price']['write_semantics']);
        $this->assertNotSame('not_proven', $master['gift_message_available']['write_semantics']);
        $questions = $this->readCsv(dirname(__DIR__, 3).'/'.AdobeCommerceCoverage::DISAGREEMENTS);
        $this->assertCount(14, $questions);
    }

    #[Test]
    public function unexplained_adobe_concept_merge_fails_validation(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".AdobeCommerceCoverage::COVERAGE);
        $rows[0]['concept_key'] = $rows[1]['concept_key'];
        $this->writeCsv("$root/".AdobeCommerceCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexplained shared Adobe concept');
        (new AdobeCommerceCoverage)->validate($root);
    }

    #[Test]
    public function bigcommerce_validator_remains_green_with_adobe_manifest_rows(): void
    {
        $metrics = (new BigCommerceCoverage)->validate(dirname(__DIR__, 3));
        $this->assertSame(136, $metrics['coverage_rows']);
        $this->assertSame('PASS', $metrics['manifest_provider_rows_preserved']);
    }

    #[Test]
    public function reusable_eav_semantics_are_not_swallowed_by_the_envelopes(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));
        foreach (['additional_attributes', 'custom_attributes'] as $container) {
            $this->assertSame('DOMAIN_CAPABILITY', $master[$container]['disposition']);
            $this->assertSame('DynamicField', $master[$container]['owner_candidate']);
        }
        foreach (['manufacturer', 'material', 'color', 'size', 'instructions', 'gtin', 'mpn', 'brand'] as $semantic) {
            $this->assertSame('REUSABLE_SEMANTIC', $master[$semantic]['disposition']);
            $this->assertSame('eav_bound_semantic_candidate', $master[$semantic]['representation_candidate']);
        }
    }

    #[Test]
    public function structured_roles_are_family_aware(): void
    {
        $members = $this->structuredRows(dirname(__DIR__, 3));

        $this->assertSame('media_role_member', $members['media_gallery_entry:label']['representation_candidate']);
        foreach (['authoritative_attribute_metadata:label', 'attribute_option:label', 'configurable_product_option:label'] as $label) {
            $this->assertNotSame('media_role_member', $members[$label]['representation_candidate']);
        }
        $this->assertSame('reference_member', $members['product_link:sku']['representation_candidate']);
        $this->assertSame('reference_member', $members['tier_price:sku']['representation_candidate']);
        $this->assertSame('value_or_context_member', $members['customizable_option:sku']['representation_candidate']);
        $this->assertSame('value_or_context_member', $members['customizable_option_value:sku']['representation_candidate']);
        $this->assertSame('pricing_value_member', $members['tier_price:price']['representation_candidate']);
        $this->assertSame('pricing_value_member', $members['base_price:price']['representation_candidate']);
        $this->assertSame('price_modifier_member', $members['customizable_option:price']['representation_candidate']);
        $this->assertSame('price_modifier_member', $members['bundle_product_link:price']['representation_candidate']);
    }

    #[Test]
    public function structured_and_master_projections_are_conservatively_read_only(): void
    {
        $members = $this->structuredRows(dirname(__DIR__, 3));
        foreach (['downloadable_link:sample_url', 'downloadable_sample:sample_url'] as $projection) {
            $this->assertSame('read_projection', $members[$projection]['read_semantics']);
            $this->assertSame('read_only', $members[$projection]['write_semantics']);
            $this->assertStringContainsString('entry_kind=derived_projection', $members[$projection]['source_context_key']);
        }
        $master = $this->masterRows(dirname(__DIR__, 3));
        foreach (['created_at', 'updated_at', 'has_options', 'required_options'] as $projection) {
            $this->assertSame('DERIVED_PROJECTION', $master[$projection]['disposition']);
            $this->assertSame('read_only', $master[$projection]['write_semantics']);
        }
    }

    #[Test]
    public function graphql_read_aliases_and_transformation_kinds_are_preserved(): void
    {
        $aliases = array_filter($this->coverage(dirname(__DIR__, 3)), fn ($row) => $row['source_file'] === AdobeCommerceCoverage::ALIASES);
        foreach (['categories', 'giftcard_amounts'] as $key) {
            $row = $this->find(array_filter($aliases, fn ($row) => str_contains($row['source_surface'], 'GraphQL')), 'external_key', $key);
            $this->assertSame('read_projection', $row['read_semantics']);
            $this->assertSame('read_only', $row['write_semantics']);
        }
        $this->assertSame('alias_with_id_code_resolution', $this->find($aliases, 'external_key', 'attribute_set_id')['representation_candidate']);
        $this->assertSame('alias_with_id_name_resolution', $this->find($aliases, 'external_key', 'tax_class_name')['representation_candidate']);
        $this->assertSame('alias_with_translated_value', $this->find($aliases, 'external_key', 'product_online')['representation_candidate']);
    }

    #[Test]
    public function giftcard_and_dynamic_envelope_parent_lineage_is_source_accurate(): void
    {
        $master = $this->masterRows(dirname(__DIR__, 3));
        $members = $this->structuredRows(dirname(__DIR__, 3));

        $this->assertSame($master['giftcard_amount']['coverage_id'], $members['giftcard_amount_list:amount']['parent_coverage_id']);
        $this->assertSame($master['giftcard_amounts']['coverage_id'], $members['giftcard_amount:value']['parent_coverage_id']);
        foreach (['dynamic_attribute_value:attribute_code', 'dynamic_attribute_value:value'] as $member) {
            $this->assertSame($master['custom_attributes']['coverage_id'], $members[$member]['parent_coverage_id']);
            $this->assertStringContainsString('envelope_parents=additional_attributes|custom_attributes', $members[$member]['source_context_key']);
        }
    }

    #[Test]
    public function alias_concept_metadata_is_explicit_and_independent_of_source_order(): void
    {
        $source = dirname(__DIR__, 3);
        $concepts = array_column($this->readCsv("$source/".AdobeCommerceCoverage::CONCEPTS), null, 'concept_key');
        $this->assertSame('UnresolvedLifecycleOwner', $concepts['adobe:alias:status']['owner_candidate']);
        $this->assertSame('GiftCard', $concepts['adobe:alias:giftcard_amount']['owner_candidate']);
        $this->assertSame('PricingTax', $concepts['adobe:alias:tax_class']['owner_candidate']);
        $this->assertSame('Connector', $concepts['adobe:alias:product_type']['owner_candidate']);

        $root = $this->temporaryCorpus();
        $master = $this->readCsv("$root/".AdobeCommerceCoverage::MASTER);
        $this->writeCsv("$root/".AdobeCommerceCoverage::MASTER, ['adobe_key_or_capability', 'entry_kind', 'cluster', 'edition_scope', 'source_surface', 'review_status'], array_reverse($master));
        (new AdobeCommerceCoverage)->generate($root);
        $reordered = array_column($this->readCsv("$root/".AdobeCommerceCoverage::CONCEPTS), null, 'concept_key');
        foreach (['status', 'giftcard_amount', 'tax_class', 'product_type'] as $group) {
            $key = 'adobe:alias:'.$group;
            foreach (['owner_candidate', 'representation_candidate', 'value_type', 'concept_status', 'review_status', 'review_note'] as $column) {
                $this->assertSame($concepts[$key][$column], $reordered[$key][$column]);
            }
        }
    }

    #[Test]
    public function contradictory_projection_write_and_incomplete_eav_scope_fail_validation(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".AdobeCommerceCoverage::COVERAGE);
        foreach ($rows as &$row) {
            if ($row['source_file'] === AdobeCommerceCoverage::MASTER && $row['external_key'] === 'created_at') {
                $row['write_semantics'] = 'surface_defined';
            }
            if ($row['source_file'] === AdobeCommerceCoverage::STRUCTURED && $row['source_object_family'] === 'dynamic_attribute_value') {
                $row['source_context_key'] = str_replace('additional_attributes|custom_attributes', 'custom_attributes', $row['source_context_key']);
            }
        }
        $this->writeCsv("$root/".AdobeCommerceCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incorrect structured role/contract/context');
        (new AdobeCommerceCoverage)->validate($root);
    }

    #[Test]
    public function unknown_master_surface_family_fails_supporting_matrix_validation(): void
    {
        $root = $this->temporaryCorpus();
        $master = $this->readCsv("$root/".AdobeCommerceCoverage::MASTER);
        $master[0]['source_surface'] = 'Unknown provider surface';
        $this->writeCsv("$root/".AdobeCommerceCoverage::MASTER, ['adobe_key_or_capability', 'entry_kind', 'cluster', 'edition_scope', 'source_surface', 'review_status'], $master);
        (new AdobeCommerceCoverage)->generate($root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unresolved Adobe source surface');
        (new AdobeCommerceCoverage)->validate($root);
    }

    #[Test]
    public function unresolved_admin_write_identity_cannot_regress_to_surface_defined(): void
    {
        $root = $this->temporaryCorpus();
        $rows = $this->readCsv("$root/".AdobeCommerceCoverage::COVERAGE);
        foreach ($rows as &$row) {
            if ($row['source_file'] === AdobeCommerceCoverage::MASTER && $row['external_key'] === 'rma_eligibility') {
                $row['write_semantics'] = 'surface_defined';
            }
        }
        $this->writeCsv("$root/".AdobeCommerceCoverage::COVERAGE, BigCommerceCoverage::COVERAGE_HEADER, $rows);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incorrect master classification/contract');
        (new AdobeCommerceCoverage)->validate($root);
    }

    private function temporaryCorpus(): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir().'/adobe-coverage-'.bin2hex(random_bytes(8));
        $files = [AdobeCommerceCoverage::MASTER, AdobeCommerceCoverage::STRUCTURED, AdobeCommerceCoverage::ALIASES, AdobeCommerceCoverage::CLUSTERS, AdobeCommerceCoverage::SOURCES, AdobeCommerceCoverage::MANIFEST, AdobeCommerceCoverage::COVERAGE, AdobeCommerceCoverage::CONCEPTS, AdobeCommerceCoverage::DISAGREEMENTS];
        foreach ($files as $file) {
            if (! is_dir($root.'/'.dirname($file))) {
                mkdir($root.'/'.dirname($file), 0777, true);
            }
            copy("$source/$file", "$root/$file");
        }

        return $root;
    }

    private function coverage(string $root): array
    {
        return $this->readCsv("$root/".AdobeCommerceCoverage::COVERAGE);
    }

    private function masterRows(string $root): array
    {
        $rows = array_filter($this->coverage($root), fn ($row) => $row['source_file'] === AdobeCommerceCoverage::MASTER);

        return array_column($rows, null, 'external_key');
    }

    private function structuredRows(string $root): array
    {
        $rows = array_filter($this->coverage($root), fn ($row) => $row['source_file'] === AdobeCommerceCoverage::STRUCTURED);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['source_object_family'].':'.$row['external_key']] = $row;
        }

        return $result;
    }

    private function find(array $rows, string $column, string $value): array
    {
        foreach ($rows as $row) {
            if ($row[$column] === $value) {
                return $row;
            }
        }
        throw new RuntimeException("Missing $column=$value");
    }

    private function owner(array $row): array
    {
        return [$row['owner_candidate'], $row['representation_candidate']];
    }

    private function hashes(string $root, array $files): array
    {
        return array_map(fn ($file) => hash_file('sha256', "$root/$file"), $files);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $values);
        }
        fclose($handle);

        return $rows;
    }

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
