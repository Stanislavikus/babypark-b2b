<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorConnectionCheckErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Connectors\Transport\TimeoutPhase;

final readonly class ConnectorConnectionCheckResult
{
    private function __construct(
        public bool $succeeded,
        public ?int $httpStatus,
        public ?ConnectorConnectionCheckErrorCode $errorCode,
        public ?TimeoutPhase $timeoutPhase,
        public ?string $vendorRequestId,
    ) {}

    public static function success(): self
    {
        return new self(true, 200, null, null, null);
    }

    public static function httpFailure(
        ConnectorConnectionCheckErrorCode $errorCode,
        int $httpStatus,
    ): self {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status.');
        }

        if (! $errorCode->isHttpFailure() || ! $errorCode->acceptsHttpStatus($httpStatus)) {
            throw new \InvalidArgumentException('Error code does not accept this HTTP status.');
        }

        return new self(false, $httpStatus, $errorCode, null, null);
    }

    public static function transportFailure(
        ConnectorConnectionCheckErrorCode $errorCode,
        ?TimeoutPhase $timeoutPhase = null,
    ): self {
        if (! $errorCode->isTransportFailure()) {
            throw new \InvalidArgumentException('Not a transport-failure error code.');
        }

        if ($timeoutPhase !== null && $errorCode !== ConnectorConnectionCheckErrorCode::TransportTimeout) {
            throw new \InvalidArgumentException('TimeoutPhase only applies to TransportTimeout.');
        }

        return new self(false, null, $errorCode, $timeoutPhase, null);
    }

    public function cause(): ?ConnectorErrorCause
    {
        return $this->errorCode?->cause();
    }

    public function actionability(): ?ConnectorErrorActionability
    {
        return $this->errorCode?->actionability();
    }

    public function messageKey(): ?string
    {
        return $this->errorCode?->messageKey();
    }

    public function technicalSummary(): ?string
    {
        if ($this->succeeded) {
            return null;
        }

        $code = $this->errorCode?->value;

        if ($this->httpStatus !== null) {
            return "HTTP {$this->httpStatus} ({$code})";
        }

        if ($this->timeoutPhase !== null) {
            $phase = match ($this->timeoutPhase) {
                TimeoutPhase::Connect => 'connect phase',
                TimeoutPhase::Transfer => 'transfer phase',
                TimeoutPhase::Unknown => 'unknown phase',
            };

            return "{$code} ({$phase})";
        }

        return $code;
    }

    /**
     * @return array<string, string>
     */
    public function safeMessageParameters(): array
    {
        return [];
    }
}
