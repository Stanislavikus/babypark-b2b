<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorSchemaFieldNormalizationStatus;
use Carbon\CarbonImmutable;

final readonly class ConnectorDiscoverySnapshotCandidate
{
    private int $fieldsReceivedCount;

    /** @param list<ConnectorDiscoveryField> $fields */
    private function __construct(
        #[\SensitiveParameter] public array $fields,
        #[\SensitiveParameter] public string $canonicalHash,
        public CarbonImmutable $capturedAt,
        int $fieldsReceived,
    ) {
        $this->fieldsReceivedCount = $fieldsReceived;
    }

    /** @param list<ConnectorDiscoveryField> $fields */
    public static function create(
        #[\SensitiveParameter] array $fields,
        #[\SensitiveParameter] string $canonicalHash,
        CarbonImmutable $capturedAt,
        int $fieldsReceived,
    ): self {
        if (! array_is_list($fields)) {
            throw new \InvalidArgumentException('Fields must be a list.');
        }
        if ($fieldsReceived < 0) {
            throw new \InvalidArgumentException('fieldsReceived must not be negative.');
        }
        if ($fieldsReceived < count($fields)) {
            throw new \InvalidArgumentException('fieldsReceived must be greater than or equal to fieldsIdentified.');
        }

        $seenKeys = [];
        $fieldHashes = [];
        foreach ($fields as $field) {
            if (! $field instanceof ConnectorDiscoveryField) {
                throw new \InvalidArgumentException('Every field must be a ConnectorDiscoveryField.');
            }
            $key = $field->field->externalFieldKey();
            if (isset($seenKeys[$key])) {
                throw new \InvalidArgumentException('Field keys must be unique.');
            }
            $seenKeys[$key] = true;
            $fieldHashes[] = CanonicalSchemaFieldHash::create($key, $field->canonicalHash);
        }

        $computedHash = (new ConnectorSchemaSnapshotV2Hasher)->hash($fieldHashes);
        if ($computedHash !== $canonicalHash) {
            throw new \InvalidArgumentException('Supplied snapshot hash does not match computed v2 hash.');
        }

        return new self($fields, $canonicalHash, $capturedAt, $fieldsReceived);
    }

    public function fieldsReceived(): int
    {
        return $this->fieldsReceivedCount;
    }

    public function fieldsIdentified(): int
    {
        return count($this->fields);
    }

    public function fieldsNormalized(): int
    {
        return count(array_filter(
            $this->fields,
            static fn (ConnectorDiscoveryField $field): bool => $field->field->normalizationStatus() === ConnectorSchemaFieldNormalizationStatus::Normalized,
        ));
    }

    public function fieldsUnclassified(): int
    {
        return $this->fieldsIdentified() - $this->fieldsNormalized();
    }

    public function canonicalHashVersion(): string
    {
        return ConnectorSchemaSnapshotV2Hasher::VERSION;
    }
}
