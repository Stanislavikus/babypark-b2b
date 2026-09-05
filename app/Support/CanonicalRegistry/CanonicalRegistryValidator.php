<?php

namespace App\Support\CanonicalRegistry;

class CanonicalRegistryValidator
{
    private const FILE_SPECS = [
        'fields' => [
            'filename' => 'canonical_product_fields.csv',
            'headers' => [
                'internal_code', 'canonical_english_name', 'uk_label', 'ru_label', 'description',
                'implementation_kind', 'storage_owner', 'field_definition_eligibility', 'binding_strategy',
                'scope', 'field_group_or_state', 'data_type_or_state', 'value_shape', 'structure_schema_ref',
                'is_localizable', 'value_localization_strategy', 'channel_value_strategy', 'inheritance_strategy',
                'is_multi_value', 'unit_family', 'status', 'mvp_tier', 'default_enabled', 'verification_status',
                'recommended_action', 'supports_admin_display', 'supports_b2b_display', 'supports_search',
                'supports_filter', 'supports_table_column', 'evidence_subject_key',
            ],
        ],
        'mappings' => [
            'filename' => 'canonical_product_field_mappings.csv',
            'headers' => [
                'internal_code', 'channel', 'external_field', 'mapping_type', 'transformation',
                'applicability_id', 'requirement_level', 'channel_schema_version', 'verification_status',
                'evidence_subject_key',
            ],
        ],
        'aliases' => [
            'filename' => 'canonical_product_field_aliases.csv',
            'headers' => [
                'internal_code', 'alias', 'normalized_alias', 'locale', 'alias_type', 'scope',
                'verification_status', 'evidence_subject_key',
            ],
        ],
        'sources' => [
            'filename' => 'canonical_product_field_sources.csv',
            'headers' => [
                'source_id', 'subject_type', 'evidence_subject_key', 'source_kind', 'source_organization',
                'source_title', 'source_url_or_state', 'source_ref_or_state', 'source_version', 'verified_at',
                'evidence_locator', 'evidence_note',
            ],
        ],
        'options' => [
            'filename' => 'canonical_product_field_options.csv',
            'headers' => [
                'option_id', 'internal_code', 'option_code', 'en_label', 'uk_label', 'ru_label', 'sort_order',
                'option_scope', 'applicability_id', 'option_source_strategy', 'value_domain_ref',
                'verification_status', 'status', 'evidence_subject_key',
            ],
        ],
        'option_mappings' => [
            'filename' => 'canonical_product_field_option_mappings.csv',
            'headers' => [
                'option_mapping_id', 'option_id', 'channel', 'external_option_value', 'mapping_type',
                'applicability_id', 'channel_schema_version', 'verification_status', 'evidence_subject_key',
            ],
        ],
        'constraints' => [
            'filename' => 'canonical_product_field_constraints.csv',
            'headers' => [
                'constraint_id', 'internal_code', 'constraint_context', 'constraint_type',
                'constraint_value_or_state', 'unit_or_state', 'applicability_id', 'verification_status',
                'evidence_subject_key',
            ],
        ],
        'applicability' => [
            'filename' => 'canonical_product_field_applicability.csv',
            'headers' => [
                'applicability_id', 'internal_code', 'context_type', 'context_key', 'channel_or_state',
                'market_or_state', 'country_or_state', 'product_type_or_state', 'category_taxonomy_or_state',
                'category_code_or_state', 'entity_level', 'parentage_level', 'operation', 'requirement_level',
                'effective_from', 'effective_to', 'schema_version', 'verification_status', 'evidence_subject_key',
            ],
        ],
        'channel_decisions' => [
            'filename' => 'canonical_product_field_channel_decisions.csv',
            'headers' => [
                'channel_decision_id', 'internal_code', 'channel', 'decision_state', 'applicability_id_or_state',
                'reason_ref_or_state', 'channel_schema_version', 'verification_status', 'evidence_subject_key',
            ],
        ],
    ];

    private const DECLARED_ENUMS = [
        'fields.status' => ['active', 'proposed', 'deprecated'],
        'fields.data_type_or_state' => [
            'text', 'long_text', 'number', 'decimal', 'money', 'boolean', 'date', 'select', 'multi_select',
            'image', 'url', 'computed', 'not_applicable', 'undecided',
        ],
        'fields.field_group_or_state' => [
            'basic_information', 'identifiers', 'pricing', 'availability', 'images_media', 'descriptions',
            'characteristics', 'b2b', 'seo', 'logistics', 'internal', 'not_applicable', 'undecided',
        ],
        'mappings.channel' => [
            'google_merchant', 'shopify', 'adobe_commerce', 'bigcommerce', 'amazon', 'rozetka', 'schema_org',
        ],
        'channel_decisions.channel' => [
            'google_merchant', 'shopify', 'adobe_commerce', 'bigcommerce', 'amazon', 'rozetka', 'schema_org',
        ],
        'channel_decisions.decision_state' => [
            'deferred', 'account_specific', 'not_applicable', 'unsupported',
        ],
        'sources.subject_type' => [
            'field', 'mapping', 'alias', 'option', 'option_mapping', 'constraint', 'applicability', 'decision', 'channel_decision',
        ],
    ];

    private const CHANNELS = [
        'google_merchant', 'shopify', 'adobe_commerce', 'bigcommerce', 'amazon', 'rozetka', 'schema_org',
    ];

    /** @var array<string, list<array<string, string>>> */
    private array $datasets = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $metrics = [];

    /** @var array<string, true> */
    private array $decisionHeadings = [];

    /** @var array<string, true> */
    private array $gapHeadings = [];

    public function __construct(
        private readonly string $dataPath,
        private readonly string $registryDocumentPath,
        private readonly ?string $gapsDocumentPath = null,
    ) {}

    /**
     * @return array{errors: list<string>, warnings: list<string>, metrics: array<string, int>}
     */
    public function validate(): array
    {
        $this->errors = [];
        $this->warnings = [];
        $this->metrics = [];
        $this->datasets = [];
        $this->decisionHeadings = $this->loadDecisionHeadings();
        $this->gapHeadings = $this->loadGapHeadings();

        foreach (self::FILE_SPECS as $key => $spec) {
            $path = rtrim($this->dataPath, '/').'/'.$spec['filename'];
            if (! is_file($path)) {
                $this->errors[] = "Missing required file: {$path}";

                continue;
            }

            $this->datasets[$key] = $this->loadCsv($path, $key, $spec['headers']);
            $this->metrics[$key] = count($this->datasets[$key]);
        }

        if ($this->errors !== []) {
            return $this->result();
        }

        $this->validateFields();
        $this->validateMappings();
        $this->validateAliases();
        $this->validateOptions();
        $this->validateOptionMappings();
        $this->validateConstraints();
        $this->validateApplicability();
        $this->validateChannelDecisions();
        $this->validateSources();
        $this->validateMissingSources();

        return $this->result();
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, metrics: array<string, int>}
     */
    private function result(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * @param  list<string>  $expectedHeaders
     * @return list<array<string, string>>
     */
    private function loadCsv(string $path, string $datasetKey, array $expectedHeaders): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->errors[] = "Unable to read file: {$path}";

            return [];
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);
            $this->errors[] = "{$path}: empty file";

            return [];
        }

        if ($headerRow !== $expectedHeaders) {
            $this->errors[] = "{$path}: header mismatch (expected exact column order per machine validation contract)";
        }

        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($data === [null] || $data === []) {
                continue;
            }

            if (count($data) !== count($expectedHeaders)) {
                $this->errors[] = "{$path}: line {$line} has ".count($data).' columns, expected '.count($expectedHeaders);

                continue;
            }

            $row = array_combine($expectedHeaders, $data);
            if ($row === false) {
                continue;
            }

            foreach ($row as $column => $value) {
                if ($value === '' || $value === null) {
                    $this->errors[] = "{$path}: line {$line} column {$column} is empty";
                }
            }

            $row['_line'] = (string) $line;
            $row['_file'] = basename($path);
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @return array<string, true> */
    private function loadDecisionHeadings(): array
    {
        if (! is_file($this->registryDocumentPath)) {
            $this->errors[] = "Missing registry document: {$this->registryDocumentPath}";

            return [];
        }

        $content = file_get_contents($this->registryDocumentPath);
        if ($content === false) {
            $this->errors[] = "Unable to read registry document: {$this->registryDocumentPath}";

            return [];
        }

        preg_match_all('/^### (DEC-\d+)/m', $content, $matches);

        $headings = [];
        foreach ($matches[1] as $decisionId) {
            $headings[$decisionId] = true;
        }

        return $headings;
    }

    /** @return array<string, true> */
    private function loadGapHeadings(): array
    {
        $path = $this->gapsDocumentPath ?? base_path('docs/IMPLEMENTATION_GAPS.md');
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        preg_match_all('/^## (GAP-\d+)/m', $content, $matches);

        $headings = [];
        foreach ($matches[1] as $gapId) {
            $headings[$gapId] = true;
        }

        return $headings;
    }

    private function validateFields(): void
    {
        $seen = [];

        foreach ($this->datasets['fields'] as $row) {
            $code = $row['internal_code'];
            if (isset($seen[$code])) {
                $this->errors[] = "fields: duplicate internal_code '{$code}'";
            }
            $seen[$code] = true;

            $this->checkDeclaredEnum('fields', 'status', $row['status'], $row);
            $this->checkDeclaredEnum('fields', 'data_type_or_state', $row['data_type_or_state'], $row);
            $this->checkDeclaredEnum('fields', 'field_group_or_state', $row['field_group_or_state'], $row);

            $expectedKey = 'field:'.$code;
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "fields: evidence_subject_key for '{$code}' must be '{$expectedKey}', got '{$row['evidence_subject_key']}'";
            }

            $this->validateLocalizableInvariant($row);

            if ($row['mvp_tier'] === 'A' && $row['default_enabled'] !== 'true') {
                $this->errors[] = "fields: mvp_tier=A requires default_enabled=true for '{$code}'";
            }

            if ($code === 'has_energy_consumption_details' && $row['data_type_or_state'] !== 'not_applicable') {
                $this->errors[] = 'fields: has_energy_consumption_details.data_type_or_state must be not_applicable';
            }
        }
    }

    /** @param array<string, string> $row */
    private function validateLocalizableInvariant(array $row): void
    {
        $pairs = [
            'true' => 'locale_value',
            'false' => 'not_localizable',
            'not_applicable' => 'not_applicable',
            'undecided' => 'requires_research',
        ];

        $localizable = $row['is_localizable'];
        $strategy = $row['value_localization_strategy'];

        if (isset($pairs[$localizable]) && $strategy !== $pairs[$localizable]) {
            $this->errors[] = "fields: is_localizable={$localizable} requires value_localization_strategy={$pairs[$localizable]} for '{$row['internal_code']}'";
        }
    }

    private function validateMappings(): void
    {
        $fieldCodes = $this->indexColumn('fields', 'internal_code');
        $applicabilityById = $this->indexBy('applicability', 'applicability_id');
        $seen = [];

        foreach ($this->datasets['mappings'] as $row) {
            $composite = implode('|', [
                $row['internal_code'],
                $row['channel'],
                $row['external_field'],
                $row['channel_schema_version'],
                $row['applicability_id'],
            ]);

            if (isset($seen[$composite])) {
                $this->errors[] = "mappings: duplicate composite key ({$composite})";
            }
            $seen[$composite] = true;

            $this->checkDeclaredEnum('mappings', 'channel', $row['channel'], $row);

            if (! isset($fieldCodes[$row['internal_code']])) {
                $this->errors[] = "mappings: unknown internal_code '{$row['internal_code']}'";
            }

            $this->checkApplicabilityFk('mappings', $row['applicability_id'], $row['internal_code'], $applicabilityById);

            $expectedKey = sprintf(
                'mapping:%s:%s:%s:%s:%s',
                $row['channel'],
                $row['internal_code'],
                $row['external_field'],
                $row['applicability_id'],
                $row['channel_schema_version'],
            );
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "mappings: evidence_subject_key mismatch for composite '{$composite}'";
            }
        }
    }

    private function validateAliases(): void
    {
        $fieldCodes = $this->indexColumn('fields', 'internal_code');
        $seen = [];

        foreach ($this->datasets['aliases'] as $row) {
            $composite = implode('|', [
                $row['internal_code'],
                $row['normalized_alias'],
                $row['locale'],
                $row['alias_type'],
                $row['scope'],
            ]);

            if (isset($seen[$composite])) {
                $this->errors[] = "aliases: duplicate composite key ({$composite})";
            }
            $seen[$composite] = true;

            if (! isset($fieldCodes[$row['internal_code']])) {
                $this->errors[] = "aliases: unknown internal_code '{$row['internal_code']}'";
            }

            $expectedKey = sprintf(
                'alias:%s:%s:%s:%s:%s',
                $row['internal_code'],
                $row['locale'],
                $row['normalized_alias'],
                $row['alias_type'],
                $row['scope'],
            );
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "aliases: evidence_subject_key mismatch for composite '{$composite}'";
            }
        }
    }

    private function validateOptions(): void
    {
        $fieldCodes = $this->indexColumn('fields', 'internal_code');
        $applicabilityById = $this->indexBy('applicability', 'applicability_id');
        $seenId = [];
        $seenComposite = [];

        foreach ($this->datasets['options'] as $row) {
            $optionId = $row['option_id'];
            if (isset($seenId[$optionId])) {
                $this->errors[] = "options: duplicate option_id '{$optionId}'";
            }
            $seenId[$optionId] = true;

            $composite = implode('|', [
                $row['internal_code'],
                $row['option_code'],
                $row['option_scope'],
                $row['applicability_id'],
            ]);
            if (isset($seenComposite[$composite])) {
                $this->errors[] = "options: duplicate secondary key ({$composite})";
            }
            $seenComposite[$composite] = true;

            if (! isset($fieldCodes[$row['internal_code']])) {
                $this->errors[] = "options: unknown internal_code '{$row['internal_code']}'";
            }

            $this->checkApplicabilityFk('options', $row['applicability_id'], $row['internal_code'], $applicabilityById);

            $expectedKey = 'option:'.$optionId;
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "options: evidence_subject_key for '{$optionId}' must be '{$expectedKey}'";
            }
        }
    }

    private function validateOptionMappings(): void
    {
        $optionsById = $this->indexBy('options', 'option_id');
        $applicabilityById = $this->indexBy('applicability', 'applicability_id');
        $seen = [];

        foreach ($this->datasets['option_mappings'] as $row) {
            $id = $row['option_mapping_id'];
            if (isset($seen[$id])) {
                $this->errors[] = "option_mappings: duplicate option_mapping_id '{$id}'";
            }
            $seen[$id] = true;

            if (! isset($optionsById[$row['option_id']])) {
                $this->errors[] = "option_mappings: unknown option_id '{$row['option_id']}'";
            }

            if (! in_array($row['channel'], self::CHANNELS, true)) {
                $this->errors[] = "option_mappings: channel '{$row['channel']}' is not a declared channel code";
            }

            $this->checkOptionMappingSemanticFk($row, $optionsById, $applicabilityById);

            $expectedKey = 'option_mapping:'.$id;
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "option_mappings: evidence_subject_key for '{$id}' must be '{$expectedKey}'";
            }
        }
    }

    /**
     * @param  array<string, array<string, string>>  $optionsById
     * @param  array<string, array<string, string>>  $applicabilityById
     * @param  array<string, string>  $row
     */
    private function checkOptionMappingSemanticFk(array $row, array $optionsById, array $applicabilityById): void
    {
        $optionId = $row['option_id'];
        $applicabilityId = $row['applicability_id'];

        if (! isset($optionsById[$optionId])) {
            return;
        }

        if (! isset($applicabilityById[$applicabilityId])) {
            $this->errors[] = "option_mappings: unknown applicability_id '{$applicabilityId}'";

            return;
        }

        $optionCode = $optionsById[$optionId]['internal_code'];
        $applicabilityCode = $applicabilityById[$applicabilityId]['internal_code'];

        if ($optionCode !== $applicabilityCode) {
            $this->errors[] = "option_mappings: semantic FK mismatch for '{$row['option_mapping_id']}' — option internal_code '{$optionCode}' != applicability internal_code '{$applicabilityCode}'";
        }
    }

    private function validateConstraints(): void
    {
        $fieldCodes = $this->indexColumn('fields', 'internal_code');
        $applicabilityById = $this->indexBy('applicability', 'applicability_id');
        $seen = [];

        foreach ($this->datasets['constraints'] as $row) {
            $id = $row['constraint_id'];
            if (isset($seen[$id])) {
                $this->errors[] = "constraints: duplicate constraint_id '{$id}'";
            }
            $seen[$id] = true;

            if (! isset($fieldCodes[$row['internal_code']])) {
                $this->errors[] = "constraints: unknown internal_code '{$row['internal_code']}'";
            }

            $this->checkApplicabilityFk('constraints', $row['applicability_id'], $row['internal_code'], $applicabilityById);

            $expectedKey = 'constraint:'.$id;
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "constraints: evidence_subject_key for '{$id}' must be '{$expectedKey}'";
            }
        }
    }

    private function validateApplicability(): void
    {
        $fieldCodes = $this->indexColumn('fields', 'internal_code');
        $seen = [];

        foreach ($this->datasets['applicability'] as $row) {
            $id = $row['applicability_id'];
            if (isset($seen[$id])) {
                $this->errors[] = "applicability: duplicate applicability_id '{$id}'";
            }
            $seen[$id] = true;

            if (! isset($fieldCodes[$row['internal_code']])) {
                $this->errors[] = "applicability: unknown internal_code '{$row['internal_code']}'";
            }

            $expectedKey = 'applicability:'.$id;
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "applicability: evidence_subject_key for '{$id}' must be '{$expectedKey}'";
            }
        }
    }

    private function validateChannelDecisions(): void
    {
        if (! isset($this->datasets['channel_decisions'])) {
            return;
        }

        $fieldCodes = $this->indexColumn('fields', 'internal_code');
        $applicabilityById = $this->indexBy('applicability', 'applicability_id');
        $seenIds = [];
        $subjectsWithSources = [];
        foreach ($this->datasets['sources'] as $sourceRow) {
            $subjectsWithSources[$sourceRow['evidence_subject_key']] = true;
        }

        foreach ($this->datasets['channel_decisions'] as $row) {
            $id = $row['channel_decision_id'];
            if (isset($seenIds[$id])) {
                $this->errors[] = "channel_decisions: duplicate channel_decision_id '{$id}'";
            }
            $seenIds[$id] = true;

            if (! preg_match('/^cd[0-9]{3,}$/', $id)) {
                $this->errors[] = "channel_decisions: channel_decision_id '{$id}' must match ^cd[0-9]{3,}$";
            }

            $this->checkDeclaredEnum('channel_decisions', 'channel', $row['channel'], $row);
            $this->checkDeclaredEnum('channel_decisions', 'decision_state', $row['decision_state'], $row);

            if (! isset($fieldCodes[$row['internal_code']])) {
                $this->errors[] = "channel_decisions: unknown internal_code '{$row['internal_code']}'";
            }

            $expectedKey = 'channel_decision:'.$id;
            if ($row['evidence_subject_key'] !== $expectedKey) {
                $this->errors[] = "channel_decisions: evidence_subject_key for '{$id}' must be '{$expectedKey}'";
            }

            $applicabilityState = $row['applicability_id_or_state'];
            if ($applicabilityState !== 'all_contexts' && ! isset($applicabilityById[$applicabilityState])) {
                $this->errors[] = "channel_decisions: unknown applicability_id_or_state '{$applicabilityState}'";
            }

            if ($applicabilityState !== 'all_contexts' && isset($applicabilityById[$applicabilityState])) {
                $applicabilityRow = $applicabilityById[$applicabilityState];
                if ($applicabilityRow['internal_code'] !== $row['internal_code']) {
                    $this->errors[] = "channel_decisions: semantic FK mismatch for '{$id}' — applicability internal_code does not match";
                }
            }

            $reason = $row['reason_ref_or_state'];
            if ($reason !== 'not_applicable' && ! isset($this->decisionHeadings[$reason]) && ! isset($this->gapHeadings[$reason])) {
                $this->errors[] = "channel_decisions: reason_ref_or_state '{$reason}' does not resolve to DEC-NNN or GAP-NNN";
            }

            if ($row['verification_status'] === 'verified' && ! isset($subjectsWithSources[$expectedKey])) {
                $this->errors[] = "channel_decisions: verification_status=verified requires at least one source row for '{$expectedKey}'";
            }
        }

        $this->validateChannelDecisionSemanticKeys();
        $this->validateMappingDecisionConflicts();
    }

    private function validateChannelDecisionSemanticKeys(): void
    {
        $grouped = [];
        foreach ($this->datasets['channel_decisions'] as $row) {
            $groupKey = implode('|', [
                $row['internal_code'],
                $row['channel'],
                $row['channel_schema_version'],
            ]);
            $grouped[$groupKey][] = $row;
        }

        foreach ($grouped as $groupKey => $rows) {
            $allContextsRows = array_filter($rows, fn (array $row): bool => $row['applicability_id_or_state'] === 'all_contexts');
            if (count($allContextsRows) > 1) {
                $this->errors[] = "channel_decisions: multiple all_contexts rows for '{$groupKey}'";
            }

            if ($allContextsRows !== [] && count($rows) > 1) {
                $this->errors[] = "channel_decisions: all_contexts row conflicts with specific-context rows for '{$groupKey}'";
            }

            $specificApplicabilities = [];
            foreach ($rows as $row) {
                if ($row['applicability_id_or_state'] === 'all_contexts') {
                    continue;
                }

                $applicabilityId = $row['applicability_id_or_state'];
                if (isset($specificApplicabilities[$applicabilityId])) {
                    $this->errors[] = "channel_decisions: duplicate specific applicability '{$applicabilityId}' for '{$groupKey}'";
                }
                $specificApplicabilities[$applicabilityId] = true;
            }
        }
    }

    private function validateMappingDecisionConflicts(): void
    {
        foreach ($this->datasets['channel_decisions'] as $decision) {
            foreach ($this->datasets['mappings'] as $mapping) {
                if ($mapping['internal_code'] !== $decision['internal_code']) {
                    continue;
                }

                if ($mapping['channel'] !== $decision['channel']) {
                    continue;
                }

                if ($mapping['channel_schema_version'] !== $decision['channel_schema_version']) {
                    continue;
                }

                if ($decision['applicability_id_or_state'] === 'all_contexts') {
                    $this->errors[] = sprintf(
                        'channel_decisions: all_contexts decision %s conflicts with mapping for %s:%s:%s',
                        $decision['channel_decision_id'],
                        $mapping['channel'],
                        $mapping['internal_code'],
                        $mapping['external_field'],
                    );

                    continue;
                }

                if ($mapping['applicability_id'] === $decision['applicability_id_or_state']) {
                    $this->errors[] = sprintf(
                        'channel_decisions: decision %s conflicts with mapping sharing applicability %s',
                        $decision['channel_decision_id'],
                        $decision['applicability_id_or_state'],
                    );
                }
            }
        }
    }

    private function validateSources(): void
    {
        $seenSourceIds = [];

        foreach ($this->datasets['sources'] as $row) {
            $sourceId = $row['source_id'];
            if (isset($seenSourceIds[$sourceId])) {
                $this->errors[] = "sources: duplicate source_id '{$sourceId}'";
            }
            $seenSourceIds[$sourceId] = true;

            $this->checkDeclaredEnum('sources', 'subject_type', $row['subject_type'], $row);

            $key = $row['evidence_subject_key'];
            $prefix = strtok($key, ':') ?: '';
            if ($prefix !== $row['subject_type']) {
                $this->errors[] = "sources: subject_type '{$row['subject_type']}' does not match evidence_subject_key prefix for '{$key}'";
            }

            if (! $this->isValidDate($row['verified_at'])) {
                $this->errors[] = "sources: verified_at must be YYYY-MM-DD for source_id '{$sourceId}', got '{$row['verified_at']}'";
            }

            if (! $this->evidenceSubjectExists($key)) {
                $this->errors[] = "sources: orphan evidence_subject_key '{$key}' (no matching subject)";
            }
        }
    }

    private function validateMissingSources(): void
    {
        $subjectsWithSources = [];
        foreach ($this->datasets['sources'] as $row) {
            $subjectsWithSources[$row['evidence_subject_key']] = true;
        }

        foreach ($this->collectExpectedSubjects() as $subjectKey) {
            if (! isset($subjectsWithSources[$subjectKey])) {
                $this->warnings[] = "Missing source evidence for subject '{$subjectKey}'";
            }
        }
    }

    /** @return list<string> */
    private function collectExpectedSubjects(): array
    {
        $subjects = [];

        foreach ($this->datasets['fields'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['mappings'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['aliases'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['options'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['option_mappings'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['constraints'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['applicability'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }
        foreach ($this->datasets['channel_decisions'] as $row) {
            $subjects[] = $row['evidence_subject_key'];
        }

        foreach (array_keys($this->decisionHeadings) as $decisionId) {
            $subjects[] = 'decision:'.$decisionId;
        }

        return array_values(array_unique($subjects));
    }

    private function evidenceSubjectExists(string $key): bool
    {
        $prefix = strtok($key, ':') ?: '';
        $remainder = substr($key, strlen($prefix) + 1);

        return match ($prefix) {
            'field' => isset($this->indexColumn('fields', 'internal_code')[$remainder]),
            'mapping' => $this->mappingCompositeExists($key),
            'alias' => $this->aliasCompositeExists($key),
            'option' => isset($this->indexColumn('options', 'option_id')[$remainder]),
            'option_mapping' => isset($this->indexColumn('option_mappings', 'option_mapping_id')[$remainder]),
            'constraint' => isset($this->indexColumn('constraints', 'constraint_id')[$remainder]),
            'applicability' => isset($this->indexColumn('applicability', 'applicability_id')[$remainder]),
            'decision' => isset($this->decisionHeadings[$remainder]),
            'channel_decision' => isset($this->indexColumn('channel_decisions', 'channel_decision_id')[$remainder]),
            default => false,
        };
    }

    private function mappingCompositeExists(string $key): bool
    {
        foreach ($this->datasets['mappings'] as $row) {
            if ($row['evidence_subject_key'] === $key) {
                return true;
            }
        }

        return false;
    }

    private function aliasCompositeExists(string $key): bool
    {
        foreach ($this->datasets['aliases'] as $row) {
            if ($row['evidence_subject_key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, string>>  $applicabilityById
     */
    private function checkApplicabilityFk(string $dataset, string $applicabilityId, string $internalCode, array $applicabilityById): void
    {
        if (! isset($applicabilityById[$applicabilityId])) {
            $this->errors[] = "{$dataset}: unknown applicability_id '{$applicabilityId}'";

            return;
        }

        $applicabilityCode = $applicabilityById[$applicabilityId]['internal_code'];
        if ($internalCode !== $applicabilityCode) {
            $this->errors[] = "{$dataset}: semantic FK mismatch — internal_code '{$internalCode}' != applicability '{$applicabilityId}' internal_code '{$applicabilityCode}'";
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function checkDeclaredEnum(string $dataset, string $column, string $value, array $row): void
    {
        $enumKey = "{$dataset}.{$column}";
        if (! isset(self::DECLARED_ENUMS[$enumKey])) {
            return;
        }

        if (! in_array($value, self::DECLARED_ENUMS[$enumKey], true)) {
            $identifier = $row['internal_code'] ?? $row['source_id'] ?? $row['option_id'] ?? $row['constraint_id'] ?? $row['applicability_id'] ?? $row['option_mapping_id'] ?? 'row';
            $this->errors[] = "{$dataset}: invalid {$column} value '{$value}' for '{$identifier}'";
        }
    }

    /** @return array<string, true> */
    private function indexColumn(string $dataset, string $column): array
    {
        $index = [];
        foreach ($this->datasets[$dataset] as $row) {
            $index[$row[$column]] = true;
        }

        return $index;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function indexBy(string $dataset, string $column): array
    {
        $index = [];
        foreach ($this->datasets[$dataset] as $row) {
            $index[$row[$column]] = $row;
        }

        return $index;
    }

    private function isValidDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
