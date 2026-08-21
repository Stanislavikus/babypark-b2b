<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final readonly class AdobeStage3EValidationEvidenceEntry
{
    public function __construct(
        public string $method,
        public string $resourceCategory,
        public string $externalTestIdentity,
        public int $delegateStatusCode,
        public ?string $responseBodySha256,
        public string $recordedAt,
        public int $counter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'method' => $this->method,
            'resource_category' => $this->resourceCategory,
            'external_test_identity' => $this->externalTestIdentity,
            'delegate_status_code' => $this->delegateStatusCode,
            'response_body_sha256' => $this->responseBodySha256,
            'recorded_at' => $this->recordedAt,
            'counter' => $this->counter,
        ];
    }
}
