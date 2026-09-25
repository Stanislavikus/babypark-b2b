<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaField;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobePaaSAttributeNormalizerTest extends TestCase
{
    private AdobePaaSAttributeNormalizer $normalizer;

    private CanonicalSchemaFieldHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new AdobePaaSAttributeNormalizer;
        $this->hasher = new CanonicalSchemaFieldHasher;
    }

    #[Test]
    public function fixture_c2_rejects_empty_options_object(): void
    {
        $malformed = json_decode(
            '{"attribute_code":"bad_select","frontend_input":"select","scope":"global","options":{}}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->expectException(ConnectorDiscoverySchemaValidationException::class);

        $this->normalizer->normalize($malformed);
    }

    #[Test]
    public function empty_top_level_object_fails_on_missing_attribute_code_not_malformed_object(): void
    {
        $raw = json_decode('{}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

        try {
            $this->normalizer->normalize($raw);
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::MissingRequiredValue, $exception->reason);
            $this->assertSame('attribute_code', $exception->path);
        }
    }

    #[Test]
    public function top_level_json_list_is_rejected_as_malformed_object(): void
    {
        $raw = json_decode('[{"attribute_code":"color"}]', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

        try {
            $this->normalizer->normalize($raw);
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::MalformedObject, $exception->reason);
            $this->assertSame('$', $exception->path);
        }
    }

    #[Test]
    public function option_row_decoded_as_json_array_is_rejected(): void
    {
        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[["red","Red"]]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        try {
            $this->normalizer->normalize($raw);
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::MalformedObject, $exception->reason);
            $this->assertSame('options[0]', $exception->path);
        }
    }

    #[Test]
    public function present_null_attribute_code_is_invalid_type_not_missing(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":null,"frontend_input":"text","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'attribute_code',
        );
    }

    #[Test]
    public function present_null_frontend_input_is_invalid_type(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":null,"scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'frontend_input',
        );
    }

    #[Test]
    public function present_null_scope_is_invalid_type(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":"text","scope":null}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'scope',
        );
    }

    #[Test]
    public function selectable_options_null_is_malformed_list(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":null}',
            ConnectorDiscoverySchemaValidationReason::MalformedList,
            'options',
        );
    }

    #[Test]
    public function option_value_null_is_invalid_type(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"Red","value":null}]}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'options[0].value',
        );
    }

    #[Test]
    public function missing_option_value_is_missing_required_value(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"Red"}]}',
            ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'options[0].value',
        );
    }

    #[Test]
    public function duplicate_option_values_fail_before_field_construction(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"A","value":"red"},{"label":"B","value":"red"}]}',
            ConnectorDiscoverySchemaValidationReason::DuplicateOptionValue,
            'normalized_payload.options',
        );
    }

    #[Test]
    public function placeholder_select_option_is_normalized_not_stripped(): void
    {
        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":" ","value":""}]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $field = $this->normalizer->normalize($raw);
        $payload = $field->normalizedPayload()->toCanonicalObject();

        $this->assertCount(1, $payload->options);
        $this->assertSame(' ', $payload->options[0]->label);
        $this->assertSame('', $payload->options[0]->value);
    }

    #[Test]
    public function is_multi_value_true_only_for_multiselect_and_gallery(): void
    {
        $gallery = $this->normalizeMinimal('gallery', 'global');
        $multiselect = $this->normalizeMinimal('multiselect', 'global', selectable: true);
        $select = $this->normalizeMinimal('select', 'global', selectable: true);

        $this->assertTrue($gallery->isMultiValue());
        $this->assertTrue($multiselect->isMultiValue());
        $this->assertFalse($select->isMultiValue());
    }

    #[Test]
    public function is_localizable_true_only_for_store_scope(): void
    {
        $store = $this->normalizeMinimal('text', 'store');
        $global = $this->normalizeMinimal('text', 'global');
        $website = $this->normalizeMinimal('text', 'website');

        $this->assertTrue($store->isLocalizable());
        $this->assertFalse($global->isLocalizable());
        $this->assertFalse($website->isLocalizable());
    }

    #[Test]
    public function non_selectable_malformed_options_are_ignored(): void
    {
        $raw = json_decode(
            '{"attribute_code":"note","frontend_input":"text","scope":"global","options":{}}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $field = $this->normalizer->normalize($raw);

        $this->assertSame('{}', json_encode($field->normalizedPayload()->toCanonicalObject()));
    }

    #[Test]
    public function unknown_top_level_properties_are_ignored(): void
    {
        $raw = json_decode(
            '{"attribute_code":"note","frontend_input":"text","scope":"global","attribute_id":99,"note":"ignored"}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $field = $this->normalizer->normalize($raw);

        $this->assertSame('note', $field->externalFieldKey());
    }

    #[Test]
    public function unknown_option_row_properties_are_ignored(): void
    {
        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"Red","value":"red","sort_order":99}]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $field = $this->normalizer->normalize($raw);
        $payload = $field->normalizedPayload()->toCanonicalObject();

        $this->assertCount(1, $payload->options);
        $this->assertSame('red', $payload->options[0]->value);
    }

    #[Test]
    public function option_values_are_sorted_bytewise_not_locale_aware(): void
    {
        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"Z","value":"ä"},{"label":"A","value":"A"}]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $payload = $this->normalizer->normalize($raw)->normalizedPayload()->toCanonicalObject();

        $this->assertSame(['A', 'ä'], array_map(static fn (\stdClass $option): string => $option->value, $payload->options));
        $this->assertNotSame(
            ['ä', 'A'],
            array_map(static fn (\stdClass $option): string => $option->value, $payload->options),
        );
    }

    #[Test]
    public function composed_and_decomposed_unicode_are_distinct_values(): void
    {
        $composed = "e\u{0301}";
        $decomposed = "\u{00E9}";

        $rawComposed = json_decode(
            sprintf('{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"C","value":"%s"}]}', $composed),
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
        $rawDecomposed = json_decode(
            sprintf('{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"D","value":"%s"}]}', $decomposed),
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $hashComposed = $this->hasher->hash($this->normalizer->normalize($rawComposed));
        $hashDecomposed = $this->hasher->hash($this->normalizer->normalize($rawDecomposed));

        $this->assertNotSame($hashComposed, $hashDecomposed);
    }

    #[Test]
    public function duplicate_detection_preserves_whitespace_and_case(): void
    {
        $raw = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"A","value":" red"},{"label":"B","value":"red "}]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $field = $this->normalizer->normalize($raw);
        $payload = $field->normalizedPayload()->toCanonicalObject();

        $this->assertCount(2, $payload->options);
    }

    #[Test]
    #[DataProvider('frontendInputMappingProvider')]
    public function maps_all_frontend_input_values(string $frontendInput, string $expectedType): void
    {
        $field = $this->normalizeMinimal($frontendInput, 'global', selectable: in_array($frontendInput, ['select', 'multiselect'], true));

        $this->assertSame($expectedType, $field->normalizedDataType());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function frontendInputMappingProvider(): iterable
    {
        yield 'text' => ['text', 'text'];
        yield 'textarea' => ['textarea', 'long_text'];
        yield 'texteditor' => ['texteditor', 'long_text'];
        yield 'date' => ['date', 'date'];
        yield 'datetime' => ['datetime', 'datetime'];
        yield 'boolean' => ['boolean', 'boolean'];
        yield 'select' => ['select', 'select'];
        yield 'multiselect' => ['multiselect', 'multi_select'];
        yield 'price' => ['price', 'money'];
        yield 'media_image' => ['media_image', 'image'];
        yield 'gallery' => ['gallery', 'image_collection'];
        yield 'weight' => ['weight', 'number'];
    }

    #[Test]
    #[DataProvider('normalizationFailureProvider')]
    public function normalization_failures_match_binding_table(
        ?string $json,
        ConnectorDiscoverySchemaValidationReason $expectedReason,
        string $expectedPath,
    ): void {
        if ($json !== null) {
            $this->assertNormalizationFailure($json, $expectedReason, $expectedPath);

            return;
        }

        $raw = new \stdClass;
        $raw->attribute_code = "\xC3\x28";
        $raw->frontend_input = 'text';
        $raw->scope = 'global';

        try {
            $this->normalizer->normalize($raw);
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame($expectedReason, $exception->reason);
            $this->assertSame($expectedPath, $exception->path);
        }
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ConnectorDiscoverySchemaValidationReason, 2: string}>
     */
    public static function normalizationFailureProvider(): iterable
    {
        yield 'missing attribute_code' => [
            '{"frontend_input":"text","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'attribute_code',
        ];

        yield 'empty attribute_code' => [
            '{"attribute_code":"","frontend_input":"text","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
            'attribute_code',
        ];

        yield 'missing frontend_input' => [
            '{"attribute_code":"color","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'frontend_input',
        ];

        yield 'empty frontend_input' => [
            '{"attribute_code":"color","frontend_input":"","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
            'frontend_input',
        ];

        yield 'unknown frontend_input' => [
            '{"attribute_code":"color","frontend_input":"unsupported_vendor_input","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::UnmappedValue,
            'frontend_input',
        ];

        yield 'missing scope' => [
            '{"attribute_code":"color","frontend_input":"text"}',
            ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'scope',
        ];

        yield 'unknown scope' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"admin"}',
            ConnectorDiscoverySchemaValidationReason::UnmappedValue,
            'scope',
        ];

        yield 'invalid is_required type' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"global","is_required":"true"}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'is_required',
        ];

        yield 'missing selectable options' => [
            '{"attribute_code":"color","frontend_input":"select","scope":"global"}',
            ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'options',
        ];

        yield 'options object not list' => [
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":{}}',
            ConnectorDiscoverySchemaValidationReason::MalformedList,
            'options',
        ];

        yield 'negative position' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"global","position":-1}',
            ConnectorDiscoverySchemaValidationReason::NegativeInteger,
            'position',
        ];

        yield 'string position' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"global","position":"10"}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'position',
        ];

        yield 'float position' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"global","position":1.5}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'position',
        ];

        yield 'bool position' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"global","position":true}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'position',
        ];

        yield 'invalid default_frontend_label type' => [
            '{"attribute_code":"color","frontend_input":"text","scope":"global","default_frontend_label":42}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'default_frontend_label',
        ];

        yield 'invalid option label type' => [
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":42,"value":"red"}]}',
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'options[0].label',
        ];

        yield 'invalid utf8 attribute_code' => [
            null,
            ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
            'attribute_code',
        ];
    }

    #[Test]
    public function empty_scope_is_unmapped_value_not_empty_required_string(): void
    {
        $this->assertNormalizationFailure(
            '{"attribute_code":"color","frontend_input":"text","scope":""}',
            ConnectorDiscoverySchemaValidationReason::UnmappedValue,
            'scope',
        );
    }

    #[Test]
    public function explicit_null_option_label_normalizes_identically_to_missing_label(): void
    {
        $withNullLabel = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":null,"value":"red"}]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
        $withoutLabel = json_decode(
            '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"value":"red"}]}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $fieldWithNullLabel = $this->normalizer->normalize($withNullLabel);
        $fieldWithoutLabel = $this->normalizer->normalize($withoutLabel);

        $canonicalWithNullLabel = json_encode($fieldWithNullLabel->normalizedPayload()->toCanonicalObject());
        $canonicalWithoutLabel = json_encode($fieldWithoutLabel->normalizedPayload()->toCanonicalObject());

        $this->assertSame('{"options":[{"value":"red"}]}', $canonicalWithNullLabel);
        $this->assertSame('{"options":[{"value":"red"}]}', $canonicalWithoutLabel);
        $this->assertSame(
            $this->hasher->hash($fieldWithNullLabel),
            $this->hasher->hash($fieldWithoutLabel),
        );
    }

    #[Test]
    public function empty_external_label_string_is_preserved(): void
    {
        $raw = json_decode(
            '{"attribute_code":"color","default_frontend_label":"","frontend_input":"text","scope":"global"}',
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $field = $this->normalizer->normalize($raw);

        $this->assertSame('', $field->externalLabel());
    }

    #[Test]
    public function missing_default_frontend_label_becomes_null(): void
    {
        $field = $this->normalizeMinimal('text', 'global');

        $this->assertNull($field->externalLabel());
    }

    private function normalizeMinimal(string $frontendInput, string $scope, bool $selectable = false): CanonicalSchemaField
    {
        $options = $selectable ? ',"options":[{"label":"A","value":"a"}]' : '';

        return $this->normalizer->normalize(json_decode(
            sprintf('{"attribute_code":"field","frontend_input":"%s","scope":"%s"%s}', $frontendInput, $scope, $options),
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    private function assertNormalizationFailure(
        string $json,
        ConnectorDiscoverySchemaValidationReason $expectedReason,
        string $expectedPath,
    ): void {
        $raw = json_decode($json, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

        try {
            $this->normalizer->normalize($raw);
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame($expectedReason, $exception->reason);
            $this->assertSame($expectedPath, $exception->path);
        }
    }
}
