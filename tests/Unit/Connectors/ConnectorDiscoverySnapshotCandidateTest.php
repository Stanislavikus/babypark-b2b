<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\ConnectorDiscoveryField;
use App\Support\Connectors\ConnectorDiscoveryIdentifiedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use App\Support\Connectors\ConnectorSchemaFieldV2Hasher;
use App\Support\Connectors\ConnectorSchemaSnapshotV2Hasher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorDiscoverySnapshotCandidateTest extends TestCase
{
    #[Test]
    public function create_accepts_valid_v2_fields_and_matching_hash(): void
    {
        $field = $this->normalizedField('color');
        $candidate = $this->candidate([$field], 1);

        $this->assertSame(1, $candidate->fieldsReceived());
        $this->assertSame(1, $candidate->fieldsIdentified());
        $this->assertSame(1, $candidate->fieldsNormalized());
        $this->assertSame(0, $candidate->fieldsUnclassified());
        $this->assertSame('v2', $candidate->canonicalHashVersion());
    }

    #[Test]
    public function mixed_normalized_and_unclassified_counts_are_derived_from_identified_fields(): void
    {
        $candidate = $this->candidate([
            $this->normalizedField('color'),
            $this->unclassifiedField('module_field'),
        ], 3);

        $this->assertSame(3, $candidate->fieldsReceived());
        $this->assertSame(2, $candidate->fieldsIdentified());
        $this->assertSame(1, $candidate->fieldsNormalized());
        $this->assertSame(1, $candidate->fieldsUnclassified());
    }

    #[Test]
    public function create_rejects_non_list_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fields must be a list.');

        ConnectorDiscoverySnapshotCandidate::create(
            ['color' => $this->normalizedField('color')],
            str_repeat('a', 64),
            CarbonImmutable::now(),
            1,
        );
    }

    #[Test]
    public function create_rejects_non_discovery_field_entries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Every field must be a ConnectorDiscoveryField.');

        ConnectorDiscoverySnapshotCandidate::create(
            [new \stdClass],
            str_repeat('a', 64),
            CarbonImmutable::now(),
            1,
        );
    }

    #[Test]
    public function create_rejects_duplicate_external_field_keys(): void
    {
        $field = $this->normalizedField('color');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field keys must be unique.');

        ConnectorDiscoverySnapshotCandidate::create(
            [$field, $field],
            str_repeat('a', 64),
            CarbonImmutable::now(),
            2,
        );
    }

    #[Test]
    public function create_rejects_hash_mismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplied snapshot hash does not match computed v2 hash.');

        ConnectorDiscoverySnapshotCandidate::create(
            [$this->normalizedField('color')],
            str_repeat('b', 64),
            CarbonImmutable::now(),
            1,
        );
    }

    #[Test]
    public function create_rejects_received_less_than_identified(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fieldsReceived must be greater than or equal to fieldsIdentified.');

        $field = $this->normalizedField('color');
        ConnectorDiscoverySnapshotCandidate::create(
            [$field],
            $this->snapshotHash([$field]),
            CarbonImmutable::now(),
            0,
        );
    }

    #[Test]
    public function create_rejects_negative_received_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fieldsReceived must not be negative.');

        $field = $this->normalizedField('color');
        ConnectorDiscoverySnapshotCandidate::create(
            [$field],
            $this->snapshotHash([$field]),
            CarbonImmutable::now(),
            -1,
        );
    }

    /** @param list<ConnectorDiscoveryField> $fields */
    private function candidate(array $fields, int $received): ConnectorDiscoverySnapshotCandidate
    {
        return ConnectorDiscoverySnapshotCandidate::create(
            $fields,
            $this->snapshotHash($fields),
            CarbonImmutable::parse('2026-09-12 12:00:00'),
            $received,
        );
    }

    /** @param list<ConnectorDiscoveryField> $fields */
    private function snapshotHash(array $fields): string
    {
        return (new ConnectorSchemaSnapshotV2Hasher)->hash(array_map(
            static fn (ConnectorDiscoveryField $field): CanonicalSchemaFieldHash => CanonicalSchemaFieldHash::create(
                $field->field->externalFieldKey(),
                $field->canonicalHash,
            ),
            $fields,
        ));
    }

    private function normalizedField(string $attributeCode): ConnectorDiscoveryField
    {
        $raw = json_decode(
            sprintf('{"attribute_code":"%s","frontend_input":"text","scope":"global"}', $attributeCode),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $identified = ConnectorDiscoveryIdentifiedField::normalized(
            (new AdobePaaSAttributeNormalizer)->normalize($raw),
        );

        return new ConnectorDiscoveryField(
            $identified,
            (new ConnectorSchemaFieldV2Hasher)->hash($identified),
        );
    }

    private function unclassifiedField(string $attributeCode): ConnectorDiscoveryField
    {
        $identified = ConnectorDiscoveryIdentifiedField::unclassified(
            $attributeCode,
            null,
            CanonicalSchemaPayload::empty(),
            ConnectorDiscoverySchemaValidationReason::UnmappedValue,
        );

        return new ConnectorDiscoveryField(
            $identified,
            (new ConnectorSchemaFieldV2Hasher)->hash($identified),
        );
    }
}
