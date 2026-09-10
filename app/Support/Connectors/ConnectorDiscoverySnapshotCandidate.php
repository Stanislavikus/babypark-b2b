<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;

final readonly class ConnectorDiscoverySnapshotCandidate
{
    private int $fieldsReceivedCount;

    /**
     * @param  list<ConnectorDiscoveryNormalizedField>  $fields
     */
    private function __construct(
        #[\SensitiveParameter] public array $fields,
        #[\SensitiveParameter] public string $canonicalHash,
        public CarbonImmutable $capturedAt,
        int $fieldsReceived,
    ) {
        $this->fieldsReceivedCount = $fieldsReceived;
    }

    /**
     * @param  list<ConnectorDiscoveryNormalizedField>  $fields
     */
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

        $fieldsNormalized = count($fields);

        if ($fieldsReceived < $fieldsNormalized) {
            throw new \InvalidArgumentException('fieldsReceived must be greater than or equal to fieldsNormalized.');
        }

        $seenKeys = [];

        foreach ($fields as $field) {
            if (! $field instanceof ConnectorDiscoveryNormalizedField) {
                throw new \InvalidArgumentException('Every field must be a ConnectorDiscoveryNormalizedField.');
            }

            $key = $field->field->externalFieldKey();

            if (isset($seenKeys[$key])) {
                throw new \InvalidArgumentException('Field keys must be unique.');
            }

            $seenKeys[$key] = true;
        }

        $hasher = new CanonicalSchemaSnapshotHasher;
        $fieldHashes = array_map(
            fn (ConnectorDiscoveryNormalizedField $field): CanonicalSchemaFieldHash => CanonicalSchemaFieldHash::create(
                $field->field->externalFieldKey(),
                $field->canonicalHash,
            ),
            $fields,
        );

        $computedHash = $hasher->hash($fieldHashes);

        if ($computedHash !== $canonicalHash) {
            throw new \InvalidArgumentException('Supplied snapshot hash does not match computed hash.');
        }

        return new self($fields, $canonicalHash, $capturedAt, $fieldsReceived);
    }

    public function fieldsReceived(): int
    {
        return $this->fieldsReceivedCount;
    }

    public function fieldsNormalized(): int
    {
        return count($this->fields);
    }
}
