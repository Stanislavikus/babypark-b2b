<?php

namespace App\Support\Connectors;

use Carbon\CarbonImmutable;

final readonly class ConnectorDiscoverySnapshotCandidate
{
    /**
     * @param  list<ConnectorDiscoveryNormalizedField>  $fields
     */
    private function __construct(
        #[\SensitiveParameter] public array $fields,
        #[\SensitiveParameter] public string $canonicalHash,
        public CarbonImmutable $capturedAt,
    ) {}

    /**
     * @param  list<ConnectorDiscoveryNormalizedField>  $fields
     */
    public static function create(
        #[\SensitiveParameter] array $fields,
        #[\SensitiveParameter] string $canonicalHash,
        CarbonImmutable $capturedAt,
    ): self {
        if (! array_is_list($fields)) {
            throw new \InvalidArgumentException('Fields must be a list.');
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

        return new self($fields, $canonicalHash, $capturedAt);
    }

    public function fieldsReceived(): int
    {
        return count($this->fields);
    }

    public function fieldsNormalized(): int
    {
        return count($this->fields);
    }
}
