<?php

namespace Tests\Unit\Connectors\Exceptions;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaField;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaOption;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SensitiveParameterValue;
use Tests\TestCase;

class ConnectorDiscoverySchemaValidationExceptionTest extends TestCase
{
    #[Test]
    public function exposes_expected_error_code_reason_and_path(): void
    {
        $exception = ConnectorDiscoverySchemaValidationException::at(
            ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'attribute_code',
        );

        $this->assertSame(
            ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed,
            $exception->errorCode(),
        );
        $this->assertSame(ConnectorDiscoverySchemaValidationReason::MissingRequiredValue, $exception->reason);
        $this->assertSame('attribute_code', $exception->path);
        $this->assertSame('missing_required_value at attribute_code', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    #[Test]
    public function reason_enum_is_closed_with_exact_backing_values(): void
    {
        $this->assertCount(13, ConnectorDiscoverySchemaValidationReason::cases());

        $expected = [
            'missing_required_value' => ConnectorDiscoverySchemaValidationReason::MissingRequiredValue,
            'empty_required_string' => ConnectorDiscoverySchemaValidationReason::EmptyRequiredString,
            'invalid_type' => ConnectorDiscoverySchemaValidationReason::InvalidType,
            'invalid_utf8' => ConnectorDiscoverySchemaValidationReason::InvalidUtf8,
            'unmapped_value' => ConnectorDiscoverySchemaValidationReason::UnmappedValue,
            'negative_integer' => ConnectorDiscoverySchemaValidationReason::NegativeInteger,
            'malformed_list' => ConnectorDiscoverySchemaValidationReason::MalformedList,
            'malformed_object' => ConnectorDiscoverySchemaValidationReason::MalformedObject,
            'duplicate_option_value' => ConnectorDiscoverySchemaValidationReason::DuplicateOptionValue,
            'duplicate_external_field_key' => ConnectorDiscoverySchemaValidationReason::DuplicateExternalFieldKey,
            'invalid_canonical_hash' => ConnectorDiscoverySchemaValidationReason::InvalidCanonicalHash,
            'unsupported_canonical_value' => ConnectorDiscoverySchemaValidationReason::UnsupportedCanonicalValue,
            'json_encoding_failed' => ConnectorDiscoverySchemaValidationReason::JsonEncodingFailed,
        ];

        foreach ($expected as $backing => $case) {
            $this->assertSame($backing, $case->value);
        }
    }

    #[Test]
    public function json_encoding_failure_has_no_previous_exception(): void
    {
        $exception = ConnectorDiscoverySchemaValidationException::at(
            ConnectorDiscoverySchemaValidationReason::JsonEncodingFailed,
            '$',
        );

        $this->assertSame(ConnectorDiscoverySchemaValidationReason::JsonEncodingFailed, $exception->reason);
        $this->assertSame('$', $exception->path);
        $this->assertNull($exception->getPrevious());
    }

    #[Test]
    public function invalid_structural_path_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid structural path:');

        ConnectorDiscoverySchemaValidationException::at(
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            'INVALID PATH',
        );
    }

    #[Test]
    public function rendered_message_does_not_contain_rejected_raw_value(): void
    {
        $sentinel = "SENTINEL_REJECTED_VALUE_4B2B1D\xC3\x28";

        try {
            CanonicalSchemaOption::fromRaw($sentinel, null, 'options[0]');
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertStringNotContainsString('SENTINEL_REJECTED_VALUE_4B2B1D', $exception->getMessage());
            $this->assertStringNotContainsString('SENTINEL_REJECTED_VALUE_4B2B1D', $exception->getTraceAsString());
        }
    }

    #[Test]
    public function downstream_triggered_failure_does_not_leak_sentinel_in_trace(): void
    {
        $sentinel = 'SENTINEL_DUPLICATE_OPTION_4B2B1D';
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');

        try {
            $this->assertTrue(ini_set('zend.exception_ignore_args', '0') !== false);
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));

            try {
                CanonicalSchemaPayload::withOptions([
                    CanonicalSchemaOption::fromRaw($sentinel, 'First', 'options[0]'),
                    CanonicalSchemaOption::fromRaw($sentinel, 'Second', 'options[1]'),
                ]);
                $this->fail('Expected validation exception');
            } catch (ConnectorDiscoverySchemaValidationException $exception) {
                $this->assertSame(
                    ConnectorDiscoverySchemaValidationReason::DuplicateOptionValue,
                    $exception->reason,
                );
                $this->assertTraceDoesNotLeakSentinel($exception, $sentinel);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    #[Test]
    public function sensitive_parameters_are_redacted_in_exception_traces(): void
    {
        $sentinel = "SENTINEL_TRACE_REDACTION_4B2B1D\xC3\x28";
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');

        try {
            $this->assertTrue(ini_set('zend.exception_ignore_args', '0') !== false);
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));

            try {
                CanonicalSchemaOption::fromRaw($sentinel, null, 'options[0]');
                $this->fail('Expected validation exception');
            } catch (ConnectorDiscoverySchemaValidationException $exception) {
                $fromRawFrame = $this->findTraceFrame($exception, 'fromRaw');

                $this->assertNotNull($fromRawFrame);
                $this->assertInstanceOf(SensitiveParameterValue::class, $fromRawFrame['args'][0] ?? null);
                $this->assertSame('options[0]', $fromRawFrame['args'][2] ?? null);
                $this->assertTraceDoesNotLeakSentinel($exception, 'SENTINEL_TRACE_REDACTION_4B2B1D');
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    #[Test]
    public function missing_attribute_code_trace_redacts_raw_std_class_from_private_helper(): void
    {
        $sentinel = 'SENTINEL_MISSING_ATTR_4B2B1D';
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        $normalizer = new AdobePaaSAttributeNormalizer;

        try {
            $this->assertTrue(ini_set('zend.exception_ignore_args', '0') !== false);
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));

            $raw = json_decode(
                sprintf(
                    '{"frontend_input":"text","scope":"global","ignored_sentinel":"%s"}',
                    $sentinel,
                ),
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );

            try {
                $normalizer->normalize($raw);
                $this->fail('Expected validation exception');
            } catch (ConnectorDiscoverySchemaValidationException $exception) {
                $this->assertSame(ConnectorDiscoverySchemaValidationReason::MissingRequiredValue, $exception->reason);
                $this->assertSame('attribute_code', $exception->path);

                $helperFrame = $this->findTraceFrame($exception, 'requirePresentNonEmptyString');
                $this->assertNotNull($helperFrame);
                $this->assertInstanceOf(SensitiveParameterValue::class, $helperFrame['args'][0] ?? null);
                $this->assertSame('attribute_code', $helperFrame['args'][1] ?? null);
                $this->assertTraceDoesNotLeakSentinel($exception, $sentinel);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    #[Test]
    public function unknown_scope_trace_redacts_scope_value_from_map_scope_helper(): void
    {
        $sentinel = 'SENTINEL_UNKNOWN_SCOPE_4B2B1D';
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        $normalizer = new AdobePaaSAttributeNormalizer;

        try {
            $this->assertTrue(ini_set('zend.exception_ignore_args', '0') !== false);
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));

            $raw = json_decode(
                sprintf(
                    '{"attribute_code":"color","frontend_input":"text","scope":"%s","ignored_sentinel":"IGNORED"}',
                    $sentinel,
                ),
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );

            try {
                $normalizer->normalize($raw);
                $this->fail('Expected validation exception');
            } catch (ConnectorDiscoverySchemaValidationException $exception) {
                $this->assertSame(ConnectorDiscoverySchemaValidationReason::UnmappedValue, $exception->reason);
                $this->assertSame('scope', $exception->path);

                $mapScopeFrame = $this->findTraceFrame($exception, 'mapScope');
                $this->assertNotNull($mapScopeFrame);
                $this->assertInstanceOf(SensitiveParameterValue::class, $mapScopeFrame['args'][0] ?? null);
                $this->assertTraceDoesNotLeakSentinel($exception, $sentinel);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    #[Test]
    public function duplicate_option_trace_redacts_raw_payload_from_build_normalized_payload_helper(): void
    {
        $sentinel = 'SENTINEL_DUP_NORMALIZER_4B2B1D';
        $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        $normalizer = new AdobePaaSAttributeNormalizer;

        try {
            $this->assertTrue(ini_set('zend.exception_ignore_args', '0') !== false);
            $this->assertSame('0', ini_get('zend.exception_ignore_args'));

            $raw = json_decode(
                sprintf(
                    '{"attribute_code":"color","frontend_input":"select","scope":"global","options":[{"label":"A","value":"%s"},{"label":"B","value":"%s"}]}',
                    $sentinel,
                    $sentinel,
                ),
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );

            try {
                $normalizer->normalize($raw);
                $this->fail('Expected validation exception');
            } catch (ConnectorDiscoverySchemaValidationException $exception) {
                $this->assertSame(ConnectorDiscoverySchemaValidationReason::DuplicateOptionValue, $exception->reason);

                $buildPayloadFrame = $this->findTraceFrame($exception, 'buildNormalizedPayload');
                $this->assertNotNull($buildPayloadFrame);
                $this->assertInstanceOf(SensitiveParameterValue::class, $buildPayloadFrame['args'][0] ?? null);
                $this->assertInstanceOf(SensitiveParameterValue::class, $buildPayloadFrame['args'][1] ?? null);
                $this->assertTraceDoesNotLeakSentinel($exception, $sentinel);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    #[Test]
    public function all_public_and_private_data_bearing_parameters_carry_sensitive_parameter_attribute(): void
    {
        $methods = [
            [AdobePaaSAttributeNormalizer::class, 'normalize', [0], false],
            [AdobePaaSAttributeNormalizer::class, 'requirePresentNonEmptyString', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'requirePresentString', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'readNullableString', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'readNullableBool', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'readPosition', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'mapFrontendInput', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'mapScope', [0], true],
            [AdobePaaSAttributeNormalizer::class, 'buildNormalizedPayload', [0, 1], true],
            [AdobePaaSAttributeNormalizer::class, 'classifyViolation', [0], true],
            [CanonicalSchemaOption::class, 'fromRaw', [0, 1], false],
            [CanonicalSchemaOption::class, '__construct', [0, 1], true],
            [CanonicalSchemaOption::class, 'classifyViolation', [0], true],
            [CanonicalSchemaPayload::class, 'withOptions', [0], false],
            [CanonicalSchemaPayload::class, '__construct', [1], true],
            [CanonicalSchemaField::class, 'create', [0, 1, 2, 3, 4, 5, 6, 7, 8], false],
            [CanonicalSchemaField::class, '__construct', [0, 1, 2, 3, 4, 5, 6, 7, 8], true],
            [CanonicalSchemaField::class, 'requirePayload', [0], true],
            [CanonicalSchemaField::class, 'requireNonEmptyString', [0], true],
            [CanonicalSchemaField::class, 'requireNullableString', [0], true],
            [CanonicalSchemaField::class, 'requireNullableBool', [0], true],
            [CanonicalSchemaField::class, 'requireNullableNonNegativeInt', [0], true],
            [CanonicalSchemaField::class, 'classifyViolation', [0], true],
            [CanonicalSchemaFieldHash::class, 'create', [0, 1], false],
            [CanonicalSchemaFieldHash::class, '__construct', [0, 1], true],
            [CanonicalSchemaFieldHash::class, 'classifyViolation', [0], true],
            [CanonicalSchemaFieldHasher::class, 'hash', [0], false],
            [CanonicalSchemaFieldHasher::class, 'buildCanonicalFieldObject', [0], true],
            [CanonicalSchemaFieldHasher::class, 'encodeCanonicalJson', [0], true],
            [CanonicalSchemaFieldHasher::class, 'sortObjectKeysRecursively', [0], true],
            [CanonicalSchemaSnapshotHasher::class, 'hash', [0], false],
            [CanonicalSchemaSnapshotHasher::class, 'buildCanonicalSnapshotObject', [0], true],
            [CanonicalSchemaSnapshotHasher::class, 'encodeCanonicalJson', [0], true],
            [CanonicalSchemaSnapshotHasher::class, 'sortObjectKeysRecursively', [0], true],
        ];

        foreach ($methods as [$class, $method, $sensitiveIndexes, $isPrivate]) {
            $reflection = $isPrivate
                ? new \ReflectionMethod($class, $method)
                : new \ReflectionMethod($class, $method);

            if ($isPrivate) {
                $this->assertTrue($reflection->isPrivate(), "{$class}::{$method} must be private");
            }

            foreach ($sensitiveIndexes as $index) {
                $parameter = $reflection->getParameters()[$index];
                $attributes = $parameter->getAttributes(\SensitiveParameter::class);
                $this->assertNotEmpty(
                    $attributes,
                    "{$class}::{$method} parameter {$parameter->getName()} must carry #[\\SensitiveParameter]",
                );
            }
        }
    }

    #[Test]
    #[DataProvider('validStructuralPathsProvider')]
    public function accepts_valid_structural_paths(string $path): void
    {
        $exception = ConnectorDiscoverySchemaValidationException::at(
            ConnectorDiscoverySchemaValidationReason::InvalidType,
            $path,
        );

        $this->assertSame($path, $exception->path);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validStructuralPathsProvider(): iterable
    {
        yield '$' => ['$'];
        yield 'attribute_code' => ['attribute_code'];
        yield 'options' => ['options'];
        yield 'options[0]' => ['options[0]'];
        yield 'options[0].value' => ['options[0].value'];
        yield 'normalized_payload.options' => ['normalized_payload.options'];
        yield 'fields[0].canonical_hash' => ['fields[0].canonical_hash'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     * @return array<string, mixed>|null
     */
    private function findTraceFrame(\Throwable $exception, string $function): ?array
    {
        foreach ($exception->getTrace() as $frame) {
            if (($frame['function'] ?? '') === $function) {
                return $frame;
            }
        }

        return null;
    }

    private function assertTraceDoesNotLeakSentinel(\Throwable $exception, string $sentinel): void
    {
        $this->inspectTraceArguments($exception->getTrace(), $sentinel);
        $this->assertStringNotContainsString($sentinel, $exception->getTraceAsString());
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     */
    private function inspectTraceArguments(array $trace, string $sentinel): void
    {
        foreach ($trace as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                $this->inspectTraceArgument($argument, $sentinel);
            }
        }
    }

    private function inspectTraceArgument(mixed $argument, string $sentinel): void
    {
        if ($argument instanceof SensitiveParameterValue) {
            return;
        }

        if ($argument instanceof \stdClass) {
            $this->fail('Raw stdClass must not be retained in exception trace arguments');
        }

        if (is_string($argument)) {
            $this->assertStringNotContainsString($sentinel, $argument);

            return;
        }

        if (is_array($argument)) {
            foreach ($argument as $nested) {
                $this->inspectTraceArgument($nested, $sentinel);
            }
        }
    }
}
