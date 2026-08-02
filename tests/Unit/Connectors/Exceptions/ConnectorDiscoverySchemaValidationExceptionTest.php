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
            ini_set('zend.exception_ignore_args', '0');

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
                $this->assertStringNotContainsString($sentinel, $exception->getMessage());
                $this->assertStringNotContainsString($sentinel, $exception->getTraceAsString());
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
            ini_set('zend.exception_ignore_args', '0');

            try {
                CanonicalSchemaOption::fromRaw($sentinel, null, 'options[0]');
                $this->fail('Expected validation exception');
            } catch (ConnectorDiscoverySchemaValidationException $exception) {
                $trace = $exception->getTrace();
                $this->assertNotEmpty($trace);

                $fromRawFrame = null;

                foreach ($trace as $frame) {
                    if (($frame['function'] ?? '') === 'fromRaw') {
                        $fromRawFrame = $frame;
                        break;
                    }
                }

                $this->assertNotNull($fromRawFrame);
                $this->assertInstanceOf(SensitiveParameterValue::class, $fromRawFrame['args'][0] ?? null);
                $this->assertSame('options[0]', $fromRawFrame['args'][2] ?? null);
                $this->assertStringNotContainsString('SENTINEL_TRACE_REDACTION_4B2B1D', $exception->getTraceAsString());
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousIgnoreArgs);
        }
    }

    #[Test]
    public function all_data_bearing_parameters_carry_sensitive_parameter_attribute(): void
    {
        $methods = [
            [AdobePaaSAttributeNormalizer::class, 'normalize', [0]],
            [CanonicalSchemaOption::class, 'fromRaw', [0, 1]],
            [CanonicalSchemaPayload::class, 'withOptions', [0]],
            [CanonicalSchemaField::class, 'create', [0, 1, 2, 3, 4, 5, 6, 7, 8]],
            [CanonicalSchemaFieldHash::class, 'create', [0, 1]],
            [CanonicalSchemaFieldHasher::class, 'hash', [0]],
            [CanonicalSchemaSnapshotHasher::class, 'hash', [0]],
        ];

        foreach ($methods as [$class, $method, $sensitiveIndexes]) {
            $reflection = new \ReflectionMethod($class, $method);

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
}
