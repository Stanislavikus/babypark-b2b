<?php

namespace Tests\Unit;

use App\Support\CanonicalRegistry\CanonicalRegistryValidator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalRegistryValidatorTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/canonical-registry-test-'.uniqid('', true);
        File::makeDirectory($this->fixtureRoot.'/data', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    #[Test]
    public function valid_minimal_fixture_passes(): void
    {
        $this->writeValidFixture();

        $result = $this->validateFixture();

        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function broken_header_fails(): void
    {
        $this->writeValidFixture();
        $path = $this->fixtureRoot.'/data/canonical_product_fields.csv';
        $content = File::get($path);
        File::put($path, str_replace('internal_code', 'bad_header', $content));

        $result = $this->validateFixture();

        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, 'header mismatch')),
        );
    }

    #[Test]
    public function duplicated_unique_field_key_fails(): void
    {
        $this->writeValidFixture(extraFieldRows: [
            $this->fieldRow('name', overrides: ['canonical_english_name' => 'Duplicate']),
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, "duplicate internal_code 'name'")),
        );
    }

    #[Test]
    public function alias_rows_with_same_normalized_alias_but_different_alias_type_are_not_duplicates(): void
    {
        $this->writeValidFixture(extraAliasRows: [
            $this->aliasRow('gtin', 'GTIN', 'gtin', 'en', 'import_header', 'global', 'alias:gtin:en:gtin:import_header:global'),
            $this->aliasRow('gtin', 'gtin', 'gtin', 'en', 'legacy_code', 'global', 'alias:gtin:en:gtin:legacy_code:global'),
        ]);

        $result = $this->validateFixture();

        $duplicateAliasErrors = collect($result['errors'])
            ->filter(fn (string $e) => str_contains($e, 'aliases: duplicate composite key'));

        $this->assertCount(0, $duplicateAliasErrors, implode("\n", $duplicateAliasErrors->all()));
    }

    #[Test]
    public function missing_foreign_key_fails(): void
    {
        $this->writeValidFixture(extraMappingRows: [
            $this->mappingRow('nonexistent_field', 'google_merchant', 'title', 'a002'),
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, "unknown internal_code 'nonexistent_field'")),
        );
    }

    #[Test]
    public function semantic_foreign_key_mismatch_fails_for_mappings(): void
    {
        $this->writeValidFixture(extraApplicabilityRows: [
            $this->applicabilityRow('a099', 'brand'),
        ], extraMappingRows: [
            $this->mappingRow('name', 'google_merchant', 'title', 'a099'),
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, 'semantic FK mismatch')),
        );
    }

    #[Test]
    public function semantic_foreign_key_mismatch_fails_for_option_mappings(): void
    {
        $this->writeValidFixture(
            extraApplicabilityRows: [
                $this->applicabilityRow('a099', 'brand'),
            ],
            extraOptionMappingRows: [
                $this->optionMappingRow('om099', 'o001', 'google_merchant', 'new', 'a099'),
            ],
        );

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, 'option_mappings: semantic FK mismatch')),
        );
    }

    #[Test]
    public function option_mapping_on_nonexistent_option_fails(): void
    {
        $this->writeValidFixture(extraOptionMappingRows: [
            $this->optionMappingRow('om999', 'o999', 'google_merchant', 'new', 'a006'),
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, "unknown option_id 'o999'")),
        );
    }

    #[Test]
    public function incorrect_evidence_subject_key_format_fails(): void
    {
        $this->writeValidFixture(fieldOverrides: [
            'evidence_subject_key' => 'field:wrong_code',
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, 'evidence_subject_key')),
        );
    }

    #[Test]
    public function orphan_evidence_subject_key_in_sources_fails(): void
    {
        $this->writeValidFixture(extraSourceRows: [
            $this->sourceRow('s900', 'field', 'field:brnad'),
            $this->sourceRow('s901', 'option', 'option:o999'),
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, "orphan evidence_subject_key 'field:brnad'")),
        );
        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, "orphan evidence_subject_key 'option:o999'")),
        );
    }

    #[Test]
    public function valid_decision_reference_with_matching_heading_is_allowed(): void
    {
        $this->writeValidFixture(
            registryMarkdown: "### DEC-001 — test decision\n",
            extraSourceRows: [
                $this->sourceRow('s902', 'decision', 'decision:DEC-001'),
            ],
        );

        $result = $this->validateFixture();

        $decisionErrors = collect($result['errors'])
            ->filter(fn (string $e) => str_contains($e, 'decision:DEC-001'));

        $this->assertCount(0, $decisionErrors, implode("\n", $decisionErrors->all()));
    }

    #[Test]
    public function decision_reference_without_matching_heading_fails(): void
    {
        $this->writeValidFixture(extraSourceRows: [
            $this->sourceRow('s903', 'decision', 'decision:DEC-999'),
        ]);

        $result = $this->validateFixture();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, "orphan evidence_subject_key 'decision:DEC-999'")),
        );
    }

    #[Test]
    public function missing_source_for_existing_subject_is_warning_not_error(): void
    {
        $this->writeValidFixture(sourceRows: []);

        $result = $this->validateFixture();

        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertTrue(
            collect($result['warnings'])->contains(fn (string $w) => str_contains($w, 'Missing source evidence')),
        );
    }

    #[Test]
    public function multiple_source_rows_for_same_evidence_subject_key_are_allowed(): void
    {
        $this->writeValidFixture(extraSourceRows: [
            $this->sourceRow('s904', 'field', 'field:name'),
            $this->sourceRow('s905', 'field', 'field:name'),
        ]);

        $result = $this->validateFixture();

        $duplicateSourceErrors = collect($result['errors'])
            ->filter(fn (string $e) => str_contains($e, 'duplicate') && str_contains($e, 'evidence_subject_key'));

        $this->assertCount(0, $duplicateSourceErrors);
        $this->assertSame([], $result['errors']);
    }

    /**
     * @param  list<array<string, string>>  $extraFieldRows
     * @param  list<array<string, string>>  $extraMappingRows
     * @param  list<array<string, string>>  $extraAliasRows
     * @param  list<array<string, string>>  $extraSourceRows
     * @param  list<array<string, string>>  $extraApplicabilityRows
     * @param  list<array<string, string>>  $extraOptionMappingRows
     * @param  array<string, string>  $fieldOverrides
     */
    private function writeValidFixture(
        array $extraFieldRows = [],
        array $extraMappingRows = [],
        array $extraAliasRows = [],
        ?array $sourceRows = null,
        array $extraSourceRows = [],
        array $extraApplicabilityRows = [],
        array $extraOptionMappingRows = [],
        array $fieldOverrides = [],
        ?string $registryMarkdown = null,
    ): void {
        $field = array_merge($this->fieldRow('name'), $fieldOverrides);
        $fields = array_merge([
            $field,
            $this->fieldRow('condition', ['mvp_tier' => 'not_applicable', 'default_enabled' => 'not_applicable']),
            $this->fieldRow('brand', ['mvp_tier' => 'not_applicable', 'default_enabled' => 'not_applicable']),
            $this->fieldRow('gtin', [
                'field_group_or_state' => 'identifiers',
                'mvp_tier' => 'not_applicable',
                'default_enabled' => 'not_applicable',
            ]),
        ], $extraFieldRows);

        $applicability = [
            $this->applicabilityRow('a002', 'name'),
            $this->applicabilityRow('a003', 'name'),
            $this->applicabilityRow('a006', 'condition'),
        ];
        $applicability = array_merge($applicability, $extraApplicabilityRows);

        $mappings = [
            $this->mappingRow('name', 'google_merchant', 'title', 'a002'),
        ];
        $mappings = array_merge($mappings, $extraMappingRows);

        $aliases = array_merge([
            $this->aliasRow('name', 'Name', 'name', 'en', 'import_header', 'global', 'alias:name:en:name:import_header:global'),
        ], $extraAliasRows);

        $options = [
            $this->optionRow('o001', 'condition', 'new', 'a006'),
        ];

        $optionMappings = array_merge([
            $this->optionMappingRow('om001', 'o001', 'google_merchant', 'new', 'a006'),
        ], $extraOptionMappingRows);

        $constraints = [
            $this->constraintRow('c001', 'name', 'a003'),
        ];

        $defaultSources = [
            $this->sourceRow('s001', 'field', 'field:name'),
            $this->sourceRow('s002', 'mapping', 'mapping:google_merchant:name:title:a002:unversioned'),
            $this->sourceRow('s003', 'alias', 'alias:name:en:name:import_header:global'),
            $this->sourceRow('s004', 'option', 'option:o001'),
            $this->sourceRow('s005', 'option_mapping', 'option_mapping:om001'),
            $this->sourceRow('s006', 'constraint', 'constraint:c001'),
            $this->sourceRow('s007', 'applicability', 'applicability:a002'),
        ];

        $sources = $sourceRows ?? array_merge($defaultSources, $extraSourceRows);

        $this->writeCsv('canonical_product_fields.csv', $this->fieldHeaders(), $fields);
        $this->writeCsv('canonical_product_field_applicability.csv', $this->applicabilityHeaders(), $applicability);
        $this->writeCsv('canonical_product_field_mappings.csv', $this->mappingHeaders(), $mappings);
        $this->writeCsv('canonical_product_field_aliases.csv', $this->aliasHeaders(), $aliases);
        $this->writeCsv('canonical_product_field_options.csv', $this->optionHeaders(), $options);
        $this->writeCsv('canonical_product_field_option_mappings.csv', $this->optionMappingHeaders(), $optionMappings);
        $this->writeCsv('canonical_product_field_constraints.csv', $this->constraintHeaders(), $constraints);
        $this->writeCsv('canonical_product_field_channel_decisions.csv', $this->channelDecisionHeaders(), []);
        $this->writeCsv('canonical_product_field_sources.csv', $this->sourceHeaders(), $sources);

        File::put(
            $this->fixtureRoot.'/CANONICAL_PRODUCT_FIELD_REGISTRY.md',
            $registryMarkdown ?? "# Fixture Registry\n",
        );
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, metrics: array<string, int>}
     */
    private function validateFixture(): array
    {
        $validator = new CanonicalRegistryValidator(
            $this->fixtureRoot.'/data',
            $this->fixtureRoot.'/CANONICAL_PRODUCT_FIELD_REGISTRY.md',
        );

        return $validator->validate();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $filename, array $headers, array $rows): void
    {
        $path = $this->fixtureRoot.'/data/'.$filename;
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }
        fclose($handle);
    }

    /** @return list<string> */
    private function fieldHeaders(): array
    {
        return [
            'internal_code', 'canonical_english_name', 'uk_label', 'ru_label', 'description',
            'implementation_kind', 'storage_owner', 'field_definition_eligibility', 'binding_strategy',
            'scope', 'field_group_or_state', 'data_type_or_state', 'value_shape', 'structure_schema_ref',
            'is_localizable', 'value_localization_strategy', 'channel_value_strategy', 'inheritance_strategy',
            'is_multi_value', 'unit_family', 'status', 'mvp_tier', 'default_enabled', 'verification_status',
            'recommended_action', 'supports_admin_display', 'supports_b2b_display', 'supports_search',
            'supports_filter', 'supports_table_column', 'evidence_subject_key',
        ];
    }

    /** @param array<string, string> $overrides */
    private function fieldRow(string $code, array $overrides = []): array
    {
        return array_merge([
            'internal_code' => $code,
            'canonical_english_name' => 'Product Name',
            'uk_label' => 'Назва',
            'ru_label' => 'Название',
            'description' => 'Test field',
            'implementation_kind' => 'core_model_property',
            'storage_owner' => 'Product',
            'field_definition_eligibility' => 'yes',
            'binding_strategy' => 'product',
            'scope' => 'system',
            'field_group_or_state' => 'basic_information',
            'data_type_or_state' => 'text',
            'value_shape' => 'single_line',
            'structure_schema_ref' => 'not_applicable',
            'is_localizable' => 'true',
            'value_localization_strategy' => 'locale_value',
            'channel_value_strategy' => 'not_applicable',
            'inheritance_strategy' => 'none',
            'is_multi_value' => 'false',
            'unit_family' => 'not_applicable',
            'status' => 'active',
            'mvp_tier' => 'A',
            'default_enabled' => 'true',
            'verification_status' => 'verified',
            'recommended_action' => 'keep_as_is',
            'supports_admin_display' => 'true',
            'supports_b2b_display' => 'true',
            'supports_search' => 'true',
            'supports_filter' => 'true',
            'supports_table_column' => 'true',
            'evidence_subject_key' => 'field:'.$code,
        ], $overrides);
    }

    /** @return list<string> */
    private function mappingHeaders(): array
    {
        return [
            'internal_code', 'channel', 'external_field', 'mapping_type', 'transformation',
            'applicability_id', 'requirement_level', 'channel_schema_version', 'verification_status',
            'evidence_subject_key',
        ];
    }

    private function mappingRow(string $code, string $channel, string $external, string $applicabilityId): array
    {
        return [
            'internal_code' => $code,
            'channel' => $channel,
            'external_field' => $external,
            'mapping_type' => 'direct',
            'transformation' => 'not_applicable',
            'applicability_id' => $applicabilityId,
            'requirement_level' => 'required',
            'channel_schema_version' => 'unversioned',
            'verification_status' => 'verified',
            'evidence_subject_key' => "mapping:{$channel}:{$code}:{$external}:{$applicabilityId}:unversioned",
        ];
    }

    /** @return list<string> */
    private function aliasHeaders(): array
    {
        return [
            'internal_code', 'alias', 'normalized_alias', 'locale', 'alias_type', 'scope',
            'verification_status', 'evidence_subject_key',
        ];
    }

    private function aliasRow(
        string $code,
        string $alias,
        string $normalized,
        string $locale,
        string $aliasType,
        string $scope,
        string $evidenceKey,
    ): array {
        return [
            'internal_code' => $code,
            'alias' => $alias,
            'normalized_alias' => $normalized,
            'locale' => $locale,
            'alias_type' => $aliasType,
            'scope' => $scope,
            'verification_status' => 'verified',
            'evidence_subject_key' => $evidenceKey,
        ];
    }

    /** @return list<string> */
    private function sourceHeaders(): array
    {
        return [
            'source_id', 'subject_type', 'evidence_subject_key', 'source_kind', 'source_organization',
            'source_title', 'source_url_or_state', 'source_ref_or_state', 'source_version', 'verified_at',
            'evidence_locator', 'evidence_note',
        ];
    }

    private function sourceRow(string $id, string $subjectType, string $evidenceKey): array
    {
        return [
            'source_id' => $id,
            'subject_type' => $subjectType,
            'evidence_subject_key' => $evidenceKey,
            'source_kind' => 'official_web_doc',
            'source_organization' => 'test',
            'source_title' => 'Test source',
            'source_url_or_state' => 'https://example.com',
            'source_ref_or_state' => 'not_applicable',
            'source_version' => 'unversioned',
            'verified_at' => '2026-07-15',
            'evidence_locator' => 'section',
            'evidence_note' => 'fixture',
        ];
    }

    /** @return list<string> */
    private function optionHeaders(): array
    {
        return [
            'option_id', 'internal_code', 'option_code', 'en_label', 'uk_label', 'ru_label', 'sort_order',
            'option_scope', 'applicability_id', 'option_source_strategy', 'value_domain_ref',
            'verification_status', 'status', 'evidence_subject_key',
        ];
    }

    private function optionRow(string $id, string $code, string $optionCode, string $applicabilityId): array
    {
        return [
            'option_id' => $id,
            'internal_code' => $code,
            'option_code' => $optionCode,
            'en_label' => 'New',
            'uk_label' => 'Новий',
            'ru_label' => 'Новый',
            'sort_order' => '10',
            'option_scope' => 'universal',
            'applicability_id' => $applicabilityId,
            'option_source_strategy' => 'external_standard',
            'value_domain_ref' => 'test:domain',
            'verification_status' => 'verified',
            'status' => 'active',
            'evidence_subject_key' => 'option:'.$id,
        ];
    }

    /** @return list<string> */
    private function optionMappingHeaders(): array
    {
        return [
            'option_mapping_id', 'option_id', 'channel', 'external_option_value', 'mapping_type',
            'applicability_id', 'channel_schema_version', 'verification_status', 'evidence_subject_key',
        ];
    }

    private function optionMappingRow(
        string $id,
        string $optionId,
        string $channel,
        string $externalValue,
        string $applicabilityId,
    ): array {
        return [
            'option_mapping_id' => $id,
            'option_id' => $optionId,
            'channel' => $channel,
            'external_option_value' => $externalValue,
            'mapping_type' => 'direct',
            'applicability_id' => $applicabilityId,
            'channel_schema_version' => 'unversioned',
            'verification_status' => 'verified',
            'evidence_subject_key' => 'option_mapping:'.$id,
        ];
    }

    /** @return list<string> */
    private function constraintHeaders(): array
    {
        return [
            'constraint_id', 'internal_code', 'constraint_context', 'constraint_type',
            'constraint_value_or_state', 'unit_or_state', 'applicability_id', 'verification_status',
            'evidence_subject_key',
        ];
    }

    private function constraintRow(string $id, string $code, string $applicabilityId): array
    {
        return [
            'constraint_id' => $id,
            'internal_code' => $code,
            'constraint_context' => 'core',
            'constraint_type' => 'max_length',
            'constraint_value_or_state' => 'undecided',
            'unit_or_state' => 'not_applicable',
            'applicability_id' => $applicabilityId,
            'verification_status' => 'verified',
            'evidence_subject_key' => 'constraint:'.$id,
        ];
    }

    /** @return list<string> */
    private function applicabilityHeaders(): array
    {
        return [
            'applicability_id', 'internal_code', 'context_type', 'context_key', 'channel_or_state',
            'market_or_state', 'country_or_state', 'product_type_or_state', 'category_taxonomy_or_state',
            'category_code_or_state', 'entity_level', 'parentage_level', 'operation', 'requirement_level',
            'effective_from', 'effective_to', 'schema_version', 'verification_status', 'evidence_subject_key',
        ];
    }

    private function applicabilityRow(string $id, string $code): array
    {
        return [
            'applicability_id' => $id,
            'internal_code' => $code,
            'context_type' => 'global',
            'context_key' => 'core:'.$code,
            'channel_or_state' => 'not_applicable',
            'market_or_state' => 'not_applicable',
            'country_or_state' => 'not_applicable',
            'product_type_or_state' => 'not_applicable',
            'category_taxonomy_or_state' => 'not_applicable',
            'category_code_or_state' => 'not_applicable',
            'entity_level' => 'product',
            'parentage_level' => 'not_applicable',
            'operation' => 'not_applicable',
            'requirement_level' => 'not_applicable',
            'effective_from' => 'undecided',
            'effective_to' => 'open_ended',
            'schema_version' => 'not_applicable',
            'verification_status' => 'verified',
            'evidence_subject_key' => 'applicability:'.$id,
        ];
    }

    /** @return list<string> */
    private function channelDecisionHeaders(): array
    {
        return [
            'channel_decision_id', 'internal_code', 'channel', 'decision_state', 'applicability_id_or_state',
            'reason_ref_or_state', 'channel_schema_version', 'verification_status', 'evidence_subject_key',
        ];
    }
}
