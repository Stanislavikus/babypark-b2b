<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

final readonly class AdobeStage3EValidationRunInput
{
    public function __construct(
        public string $connectorAccountId,
        public string $productVariantId,
        public string $expectHost,
        public bool $executeRealWrites,
        public string $ackWriteSku,
        public bool $restoreAfterKnownApplied,
        public bool $simulateTransportLossAfterWrite,
    ) {}

    public function scenarioCode(): string
    {
        return $this->simulateTransportLossAfterWrite
            ? 'transport_loss_after_write'
            : 'baseline_simple_write';
    }
}
