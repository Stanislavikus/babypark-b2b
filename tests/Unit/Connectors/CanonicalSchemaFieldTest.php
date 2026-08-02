<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\CanonicalSchemaField;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaOption;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalSchemaFieldTest extends TestCase
{
    #[Test]
    public function create_accepts_valid_canonical_field(): void
    {
        $field = CanonicalSchemaField::create(
            'color',
            'Color',
            'select',
            true,
            false,
            false,
            'global',
            CanonicalSchemaPayload::empty(),
            10,
        );

        $this->assertSame('color', $field->externalFieldKey());
        $this->assertSame('Color', $field->externalLabel());
        $this->assertSame('select', $field->normalizedDataType());
        $this->assertTrue($field->isRequired());
        $this->assertFalse($field->isMultiValue());
        $this->assertFalse($field->isLocalizable());
        $this->assertSame('global', $field->externalScope());
        $this->assertSame(10, $field->sortOrder());
    }

    #[Test]
    public function rejects_non_payload_normalized_payload_with_invalid_type(): void
    {
        $this->expectException(ConnectorDiscoverySchemaValidationException::class);
        $this->expectExceptionMessage('invalid_type at normalized_payload');

        CanonicalSchemaField::create(
            'color',
            null,
            'text',
            null,
            false,
            false,
            'global',
            [],
            null,
        );
    }

    #[Test]
    public function rejects_object_normalized_payload_with_unsupported_canonical_value(): void
    {
        try {
            CanonicalSchemaField::create(
                'color',
                null,
                'text',
                null,
                false,
                false,
                'global',
                new \stdClass,
                null,
            );
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue, $exception->reason);
            $this->assertSame('normalized_payload', $exception->path);
        }
    }

    #[Test]
    public function rejects_empty_external_field_key(): void
    {
        try {
            CanonicalSchemaField::create(
                '',
                null,
                'text',
                null,
                false,
                false,
                'global',
                CanonicalSchemaPayload::empty(),
                null,
            );
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::EmptyRequiredString, $exception->reason);
            $this->assertSame('external_field_key', $exception->path);
        }
    }

    #[Test]
    public function rejects_float_sort_order_with_invalid_type(): void
    {
        try {
            CanonicalSchemaField::create(
                'color',
                null,
                'text',
                null,
                false,
                false,
                'global',
                CanonicalSchemaPayload::empty(),
                1.5,
            );
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::InvalidType, $exception->reason);
            $this->assertSame('sort_order', $exception->path);
        }
    }

    #[Test]
    public function rejects_negative_sort_order(): void
    {
        try {
            CanonicalSchemaField::create(
                'color',
                null,
                'text',
                null,
                false,
                false,
                'global',
                CanonicalSchemaPayload::empty(),
                -1,
            );
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::NegativeInteger, $exception->reason);
            $this->assertSame('sort_order', $exception->path);
        }
    }

    #[Test]
    public function canonical_schema_option_rejects_invalid_utf8_label(): void
    {
        try {
            CanonicalSchemaOption::fromRaw('red', "\xC3\x28", 'options[0]');
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::InvalidUtf8, $exception->reason);
            $this->assertSame('options[0].label', $exception->path);
        }
    }

    #[Test]
    public function canonical_schema_option_distinguishes_invalid_type_from_unsupported_value(): void
    {
        try {
            CanonicalSchemaOption::fromRaw(42, null, 'options[0]');
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::InvalidType, $exception->reason);
            $this->assertSame('options[0].value', $exception->path);
        }

        try {
            CanonicalSchemaOption::fromRaw(new \stdClass, null, 'options[0]');
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue, $exception->reason);
            $this->assertSame('options[0].value', $exception->path);
        }
    }

    #[Test]
    public function canonical_schema_field_hash_distinguishes_invalid_type_from_unsupported_value(): void
    {
        try {
            CanonicalSchemaFieldHash::create(42, str_repeat('a', 64));
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::InvalidType, $exception->reason);
            $this->assertSame('external_field_key', $exception->path);
        }

        try {
            CanonicalSchemaFieldHash::create(new \stdClass, str_repeat('a', 64));
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue, $exception->reason);
            $this->assertSame('external_field_key', $exception->path);
        }
    }

    #[Test]
    public function payload_empty_encodes_to_object_and_with_empty_options_encodes_to_list(): void
    {
        $empty = CanonicalSchemaPayload::empty()->toCanonicalObject();
        $withEmptyOptions = CanonicalSchemaPayload::withOptions([])->toCanonicalObject();

        $this->assertSame('{}', json_encode($empty));
        $this->assertSame('{"options":[]}', json_encode($withEmptyOptions));
        $this->assertTrue($empty instanceof \stdClass);
        $this->assertTrue(property_exists($withEmptyOptions, 'options'));
        $this->assertSame([], $withEmptyOptions->options);
    }

    #[Test]
    public function to_canonical_object_returns_fresh_detached_graph_each_call(): void
    {
        $payload = CanonicalSchemaPayload::withOptions([
            CanonicalSchemaOption::fromRaw('red', 'Red', 'options[0]'),
        ]);
        $field = CanonicalSchemaField::create(
            'color',
            'Color',
            'select',
            true,
            false,
            false,
            'global',
            $payload,
            10,
        );
        $hasher = new CanonicalSchemaFieldHasher;
        $expectedHash = $hasher->hash($field);

        $first = $payload->toCanonicalObject();
        $first->options[0]->value = 'mutated';
        $second = $payload->toCanonicalObject();

        $this->assertSame('red', $second->options[0]->value);
        $this->assertSame($expectedHash, $hasher->hash($field));
    }

    #[Test]
    #[DataProvider('bindingReasonMappingProvider')]
    public function binding_reason_mapping_is_exact(
        callable $action,
        ConnectorDiscoverySchemaValidationReason $expectedReason,
        string $expectedPath,
    ): void {
        try {
            $action();
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame($expectedReason, $exception->reason);
            $this->assertSame($expectedPath, $exception->path);
        }
    }

    /**
     * @return iterable<string, array{0: callable(): void, 1: ConnectorDiscoverySchemaValidationReason, 2: string}>
     */
    public static function bindingReasonMappingProvider(): iterable
    {
        yield 'missing required property' => [
            fn () => CanonicalSchemaOption::fromRaw(null, null, 'options[0]'),
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'options[0].value',
        ];

        yield 'required string empty' => [
            fn () => CanonicalSchemaField::create('', null, 'text', null, false, false, 'global', CanonicalSchemaPayload::empty(), null),
            ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
            'external_field_key',
        ];

        yield 'primitive wrong type' => [
            fn () => CanonicalSchemaField::create('color', 42, 'text', null, false, false, 'global', CanonicalSchemaPayload::empty(), null),
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'external_label',
        ];

        yield 'invalid utf8' => [
            fn () => CanonicalSchemaOption::fromRaw("\xC3\x28", null, 'options[0]'),
            ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
            'options[0].value',
        ];

        yield 'duplicate option value' => [
            fn () => CanonicalSchemaPayload::withOptions([
                CanonicalSchemaOption::fromRaw('red', 'A', 'options[0]'),
                CanonicalSchemaOption::fromRaw('red', 'B', 'options[1]'),
            ]),
            ConnectorDiscoverySchemaValidationReason::DuplicateOptionValue,
            'normalized_payload.options',
        ];

        yield 'duplicate external field key' => [
            fn () => (new CanonicalSchemaSnapshotHasher)->hash([
                CanonicalSchemaFieldHash::create('color', str_repeat('a', 64)),
                CanonicalSchemaFieldHash::create('color', str_repeat('b', 64)),
            ]),
            ConnectorDiscoverySchemaValidationReason::DuplicateExternalFieldKey,
            'fields',
        ];

        yield 'invalid canonical hash' => [
            fn () => CanonicalSchemaFieldHash::create('color', 'not-a-hash'),
            ConnectorDiscoverySchemaValidationReason::InvalidCanonicalHash,
            'canonical_hash',
        ];

        yield 'unsupported canonical value' => [
            fn () => CanonicalSchemaField::create('color', new \stdClass, 'text', null, false, false, 'global', CanonicalSchemaPayload::empty(), null),
            ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue,
            'external_label',
        ];

        yield 'malformed list' => [
            fn () => CanonicalSchemaPayload::withOptions(['not-an-option']),
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'normalized_payload.options',
        ];
    }
}
