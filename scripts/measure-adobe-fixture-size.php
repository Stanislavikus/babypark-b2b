#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Non-runtime fixture measurement for Task 4B-0 retention estimates.
 * Uses representative Adobe attribute payload — no production credentials.
 */
$fixturePath = dirname(__DIR__).'/docs/data/fixtures/adobe_commerce/products_attributes_sample.json';

if (! is_readable($fixturePath)) {
    fwrite(STDERR, "Fixture not found: {$fixturePath}\n");
    exit(1);
}

$raw = file_get_contents($fixturePath);
$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$goldenCodes = ['sku', 'name', 'description', 'short_description', 'status'];

function normalizeAdobeField(array $item): array
{
    $options = $item['options'] ?? [];
    usort($options, static fn (array $a, array $b): int => strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? '')));

    return [
        'external_field_key' => $item['attribute_code'],
        'external_label' => $item['default_frontend_label'] ?? null,
        'normalized_data_type' => $item['frontend_input'] ?? 'unknown',
        'is_required' => (bool) ($item['is_required'] ?? false),
        'is_multi_value' => false,
        'is_localizable' => false,
        'external_scope' => 'product',
        'options' => array_map(static fn (array $o): array => [
            'value' => (string) ($o['value'] ?? ''),
            'label' => (string) ($o['label'] ?? ''),
        ], $options),
        'backend_type' => $item['backend_type'] ?? null,
        'is_user_defined' => (bool) ($item['is_user_defined'] ?? false),
    ];
}

function canonicalHash(array $normalized): string
{
    ksort($normalized);
    if (isset($normalized['options']) && is_array($normalized['options'])) {
        usort($normalized['options'], static fn (array $a, array $b): int => strcmp($a['value'], $b['value']));
    }

    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$normalizedFields = [];
foreach ($decoded['items'] as $item) {
    $normalized = normalizeAdobeField($item);
    $normalized['canonical_hash'] = canonicalHash($normalized);
    $normalizedFields[] = $normalized;
}

$snapshotEnvelope = [
    'workspace_id' => '00000000-0000-0000-0000-000000000001',
    'connector_account_id' => '00000000-0000-0000-0000-000000000002',
    'schema_source_id' => '00000000-0000-0000-0000-000000000003',
    'field_count' => count($normalizedFields),
    'fields' => $normalizedFields,
    'captured_at' => '2026-07-21T12:00:00Z',
];

$diffItem = [
    'change_type' => 'changed',
    'external_field_key' => 'description',
    'changed_paths' => ['is_required'],
    'before' => ['is_required' => false],
    'after' => ['is_required' => true],
];

$rawBytes = strlen($raw);
$normalizedBytes = strlen(json_encode($snapshotEnvelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$perFieldAvg = count($normalizedFields) > 0 ? (int) round($normalizedBytes / count($normalizedFields)) : 0;
$optionsHeavy = max(array_map(static fn (array $f): int => strlen(json_encode($f)), $normalizedFields));
$diffItemBytes = strlen(json_encode($diffItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "=== Adobe fixture size measurement ===\n";
echo "fixture_path: {$fixturePath}\n";
echo "raw_payload_bytes: {$rawBytes}\n";
echo "normalized_snapshot_bytes: {$normalizedBytes}\n";
echo 'field_count: '.count($normalizedFields)."\n";
echo "avg_bytes_per_field: {$perFieldAvg}\n";
echo "largest_normalized_field_bytes: {$optionsHeavy}\n";
echo "sample_diff_item_bytes: {$diffItemBytes}\n";
echo 'golden_field_codes_present: '.implode(', ', array_intersect($goldenCodes, array_column($normalizedFields, 'external_field_key')))."\n";

// Hash stability demo
$reordered = $decoded;
$reordered['items'] = array_reverse($decoded['items']);
$reorderedNormalized = array_map(static fn (array $item): string => canonicalHash(normalizeAdobeField($item)), $reordered['items']);
$originalNormalized = array_map(static fn (array $item): string => canonicalHash(normalizeAdobeField($item)), $decoded['items']);
sort($reorderedNormalized);
sort($originalNormalized);
echo 'hash_order_invariant: '.($reorderedNormalized === $originalNormalized ? 'pass' : 'fail')."\n";

$changed = normalizeAdobeField($decoded['items'][2]);
$changed['is_required'] = true;
echo 'hash_detects_required_change: '.(canonicalHash($changed) !== canonicalHash(normalizeAdobeField($decoded['items'][2])) ? 'pass' : 'fail')."\n";
