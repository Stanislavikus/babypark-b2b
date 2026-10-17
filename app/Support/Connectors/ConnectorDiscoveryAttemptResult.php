<?php

namespace App\Support\Connectors;

use App\Enums\ConnectorDiscoveryRunErrorCode;
use App\Enums\ConnectorErrorActionability;
use App\Enums\ConnectorErrorCause;
use App\Support\Connectors\Transport\TimeoutPhase;

final readonly class ConnectorDiscoveryAttemptResult
{
    private function __construct(
        public bool $succeeded,
        public ?int $httpStatus,
        public ?ConnectorDiscoveryRunErrorCode $errorCode,
        public ?TimeoutPhase $timeoutPhase,
        public ?int $retryAfterSeconds,
        #[\SensitiveParameter] public ?ConnectorDiscoverySnapshotCandidate $snapshotCandidate,
    ) {}

    public static function success(
        #[\SensitiveParameter] ConnectorDiscoverySnapshotCandidate $candidate,
    ): self {
        return new self(true, null, null, null, null, $candidate);
    }

    public static function httpFailure(
        ConnectorDiscoveryRunErrorCode $errorCode,
        int $httpStatus,
        ?int $retryAfterSeconds = null,
    ): self {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status.');
        }

        if (! $errorCode->isHttpFailure() || ! $errorCode->acceptsHttpStatus($httpStatus)) {
            throw new \InvalidArgumentException('Error code does not accept this HTTP status.');
        }

        if ($retryAfterSeconds !== null) {
            if ($errorCode !== ConnectorDiscoveryRunErrorCode::AdobeRateLimited || $httpStatus !== 429) {
                throw new \InvalidArgumentException('Retry-After only applies to AdobeRateLimited with HTTP 429.');
            }

            if ($retryAfterSeconds < 0 || $retryAfterSeconds > 300) {
                throw new \InvalidArgumentException('Retry-After seconds must be between 0 and 300.');
            }
        }

        return new self(false, $httpStatus, $errorCode, null, $retryAfterSeconds, null);
    }

    public static function transportFailure(
        ConnectorDiscoveryRunErrorCode $errorCode,
        ?TimeoutPhase $timeoutPhase = null,
    ): self {
        if (! $errorCode->isTransportFailure()) {
            throw new \InvalidArgumentException('Not a transport-failure error code.');
        }

        if ($timeoutPhase !== null && $errorCode !== ConnectorDiscoveryRunErrorCode::TransportTimeout) {
            throw new \InvalidArgumentException('TimeoutPhase only applies to TransportTimeout.');
        }

        return new self(false, null, $errorCode, $timeoutPhase, null, null);
    }

    public static function schemaValidationFailure(): self
    {
        return new self(
            false,
            null,
            ConnectorDiscoveryRunErrorCode::DiscoverySchemaValidationFailed,
            null,
            null,
            null,
        );
    }

    public static function paginationFailure(ConnectorDiscoveryRunErrorCode $errorCode): self
    {
        if (! in_array($errorCode, [
            ConnectorDiscoveryRunErrorCode::DiscoveryPaginationLimitExceeded,
            ConnectorDiscoveryRunErrorCode::DiscoveryIncompletePagination,
        ], true)) {
            throw new \InvalidArgumentException('Invalid pagination error code.');
        }

        return new self(false, null, $errorCode, null, null, null);
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
}
