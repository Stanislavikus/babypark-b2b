<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryNormalizedField;
use App\Support\Connectors\ConnectorDiscoverySnapshotCandidate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorDiscoverySnapshotCandidateTest extends TestCase
{
    #[Test]
    public function create_accepts_valid_fields_and_matching_hash(): void
    {
        $field = $this->normalizedField('color');
        $snapshotHasher = new CanonicalSchemaSnapshotHasher;
        $hash = $snapshotHasher->hash([
            CanonicalSchemaFieldHash::create('color', $field->canonicalHash),
        ]);

        $candidate = ConnectorDiscoverySnapshotCandidate::create(
            [$field],
            $hash,
            CarbonImmutable::parse('2026-08-02 12:00:00'),
        );

        $this->assertSame(1, $candidate->fieldsReceived());
        $this->assertSame(1, $candidate->fieldsNormalized());
        $this->assertSame($hash, $candidate->canonicalHash);
        $this->assertSame('2026-08-02 12:00:00', $candidate->capturedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function create_rejects_non_list_fields(): void
    {
        $field = $this->normalizedField('color');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fields must be a list.');

        ConnectorDiscoverySnapshotCandidate::create(
            ['color' => $field],
            str_repeat('a', 64),
            CarbonImmutable::now(),
        );
    }

    #[Test]
    public function create_rejects_non_normalized_field_entries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Every field must be a ConnectorDiscoveryNormalizedField.');

        ConnectorDiscoverySnapshotCandidate::create(
            [new \stdClass],
            str_repeat('a', 64),
            CarbonImmutable::now(),
        );
    }

    #[Test]
    public function create_rejects_duplicate_external_field_keys(): void
    {
        $field = $this->normalizedField('color');
        $snapshotHasher = new CanonicalSchemaSnapshotHasher;
        $hash = $snapshotHasher->hash([
            CanonicalSchemaFieldHash::create('color', $field->canonicalHash),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field keys must be unique.');

        ConnectorDiscoverySnapshotCandidate::create(
            [$field, $field],
            $hash,
            CarbonImmutable::now(),
        );
    }

    #[Test]
    public function create_rejects_hash_mismatch(): void
    {
        $field = $this->normalizedField('color');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplied snapshot hash does not match computed hash.');

        ConnectorDiscoverySnapshotCandidate::create(
            [$field],
            str_repeat('b', 64),
            CarbonImmutable::now(),
        );
    }

    private function normalizedField(string $attributeCode): ConnectorDiscoveryNormalizedField
    {
        $normalizer = new AdobePaaSAttributeNormalizer;
        $fieldHasher = new CanonicalSchemaFieldHasher;

        $raw = json_decode(
            sprintf(
                '{"attribute_code":"%s","frontend_input":"text","scope":"global"}',
                $attributeCode,
            ),
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );
        $canonicalField = $normalizer->normalize($raw);

        return new ConnectorDiscoveryNormalizedField(
            $canonicalField,
            $fieldHasher->hash($canonicalField),
        );
    }
}
