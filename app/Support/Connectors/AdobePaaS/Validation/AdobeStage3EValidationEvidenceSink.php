<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final class AdobeStage3EValidationEvidenceSink
{
    /** @var list<AdobeStage3EValidationEvidenceEntry> */
    private array $entries = [];

    private int $counter = 0;

    public function record(
        string $method,
        string $resourceCategory,
        string $externalTestIdentity,
        int $delegateStatusCode,
        ?string $responseBody,
    ): void {
        $this->counter++;

        $this->entries[] = new AdobeStage3EValidationEvidenceEntry(
            method: $method,
            resourceCategory: $resourceCategory,
            externalTestIdentity: $externalTestIdentity,
            delegateStatusCode: $delegateStatusCode,
            responseBodySha256: $responseBody !== null ? hash('sha256', $responseBody) : null,
            recordedAt: now()->toIso8601String(),
            counter: $this->counter,
        );
    }

    /**
     * @return list<AdobeStage3EValidationEvidenceEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sanitizedEntries(): array
    {
        return array_map(
            static fn (AdobeStage3EValidationEvidenceEntry $entry): array => $entry->toSanitizedArray(),
            $this->entries,
        );
    }
}
