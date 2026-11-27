<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\CanonicalSchemaField;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaPayload;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\ConnectorDiscoveryIdentifiedField;
use App\Support\Connectors\ConnectorSchemaFieldV2Hasher;
use App\Support\Connectors\ConnectorSchemaSnapshotV2Hasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorSchemaV2HasherTest extends TestCase
{
    #[Test]
    public function snapshot_hash_is_independent_of_field_order(): void
    {
        $left = $this->normalized('alpha');
        $right = $this->normalized('beta');
        $fieldHasher = new ConnectorSchemaFieldV2Hasher;
        $snapshotHasher = new ConnectorSchemaSnapshotV2Hasher;

        $alpha = CanonicalSchemaFieldHash::create('alpha', $fieldHasher->hash($left));
        $beta = CanonicalSchemaFieldHash::create('beta', $fieldHasher->hash($right));

        $this->assertSame(
            $snapshotHasher->hash([$alpha, $beta]),
            $snapshotHasher->hash([$beta, $alpha]),
        );
    }

    #[Test]
    public function classification_relevant_provider_metadata_changes_field_hash(): void
    {
        $hasher = new ConnectorSchemaFieldV2Hasher;

        $plain = $this->normalized('color', sourceModel: null);
        $special = $this->normalized(
            'color',
            sourceModel: 'Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status',
        );

        $this->assertNotSame($hasher->hash($plain), $hasher->hash($special));
    }

    #[Test]
    public function normalization_status_and_reason_participate_in_field_hash(): void
    {
        $hasher = new ConnectorSchemaFieldV2Hasher;
        $normalized = $this->normalized('mystery');
        $unclassified = ConnectorDiscoveryIdentifiedField::unclassified(
            'mystery',
            'Mystery',
            $this->payload(frontendInput: 'vendor_extension'),
            ConnectorDiscoverySchemaValidationReason::UnmappedValue,
        );

        $this->assertNotSame($hasher->hash($normalized), $hasher->hash($unclassified));
    }

    #[Test]
    public function service_only_field_presence_changes_v2_snapshot_hash(): void
    {
        $fieldHasher = new ConnectorSchemaFieldV2Hasher;
        $snapshotHasher = new ConnectorSchemaSnapshotV2Hasher;
        $normal = $this->normalized('name');
        $serviceOnly = ConnectorDiscoveryIdentifiedField::unclassified(
            'links_title',
            null,
            $this->payload(frontendInput: null),
            null,
        );

        $nameHash = CanonicalSchemaFieldHash::create('name', $fieldHasher->hash($normal));
        $serviceHash = CanonicalSchemaFieldHash::create('links_title', $fieldHasher->hash($serviceOnly));

        $this->assertNotSame(
            $snapshotHasher->hash([$nameHash]),
            $snapshotHasher->hash([$nameHash, $serviceHash]),
        );
    }

    #[Test]
    public function unclassified_field_hash_is_deterministic(): void
    {
        $field = ConnectorDiscoveryIdentifiedField::unclassified(
            'mystery',
            null,
            $this->payload(frontendInput: 'vendor_extension'),
            ConnectorDiscoverySchemaValidationReason::UnmappedValue,
        );
        $hasher = new ConnectorSchemaFieldV2Hasher;

        $this->assertSame($hasher->hash($field), $hasher->hash($field));
    }

    #[Test]
    public function v1_and_v2_hash_contracts_are_not_silently_equivalent(): void
    {
        $canonical = CanonicalSchemaField::create(
            'name',
            'Name',
            'text',
            true,
            false,
            false,
            'global',
            CanonicalSchemaPayload::empty(),
            1,
        );
        $v1FieldHash = (new CanonicalSchemaFieldHasher)->hash($canonical);
        $v1Snapshot = (new CanonicalSchemaSnapshotHasher)->hash([
            CanonicalSchemaFieldHash::create('name', $v1FieldHash),
        ]);

        $identified = ConnectorDiscoveryIdentifiedField::normalized($canonical);
        $v2FieldHash = (new ConnectorSchemaFieldV2Hasher)->hash($identified);
        $v2Snapshot = (new ConnectorSchemaSnapshotV2Hasher)->hash([
            CanonicalSchemaFieldHash::create('name', $v2FieldHash),
        ]);

        $this->assertNotSame($v1FieldHash, $v2FieldHash);
        $this->assertNotSame($v1Snapshot, $v2Snapshot);
    }

    private function normalized(string $key, ?string $sourceModel = null): ConnectorDiscoveryIdentifiedField
    {
        return ConnectorDiscoveryIdentifiedField::normalized(CanonicalSchemaField::create(
            $key,
            ucfirst($key),
            'select',
            false,
            false,
            false,
            'global',
            $this->payload(frontendInput: 'select', sourceModel: $sourceModel),
            1,
        ));
    }

    private function payload(?string $frontendInput, ?string $sourceModel = null): CanonicalSchemaPayload
    {
        $metadata = new \stdClass;
        $metadata->frontend_input = $frontendInput;
        $metadata->scope = 'global';
        $metadata->backend_type = 'int';
        $metadata->is_user_defined = false;
        $metadata->is_visible = true;
        $metadata->apply_to = [];
        $metadata->validation_rules = [];
        $metadata->is_unique = '0';

        if ($sourceModel !== null) {
            $metadata->source_model = $sourceModel;
        }

        return CanonicalSchemaPayload::withProviderMetadata('adobe.product_attribute.v1', $metadata);
    }
}
