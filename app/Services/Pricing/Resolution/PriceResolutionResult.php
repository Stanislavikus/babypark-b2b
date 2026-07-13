<?php

namespace App\Services\Pricing\Resolution;

use App\Exceptions\Pricing\PriceListConfigurationException;
use App\Exceptions\Pricing\PriceNotAvailableException;
use App\Services\Pricing\ResolvedPrice;
use LogicException;
use RuntimeException;

final readonly class PriceResolutionResult
{
    /**
     * @param  list<PriceResolutionReason>  $reasonCodes
     */
    private function __construct(
        public PriceResolutionStatus $status,
        public ?ResolvedPrice $price,
        public array $reasonCodes,
        public PriceResolutionTrace $trace,
        public ?PriceResolutionFailure $failure,
    ) {}

    /**
     * @param  list<PriceResolutionReason>  $reasonCodes
     */
    public static function resolved(ResolvedPrice $price, array $reasonCodes, PriceResolutionTrace $trace): self
    {
        return new self(PriceResolutionStatus::Resolved, $price, $reasonCodes, $trace, null);
    }

    /**
     * @param  list<PriceResolutionReason>  $reasonCodes
     */
    public static function unavailable(array $reasonCodes, PriceResolutionTrace $trace, PriceResolutionFailure $failure): self
    {
        return new self(PriceResolutionStatus::Unavailable, null, $reasonCodes, $trace, $failure);
    }

    /**
     * @param  list<PriceResolutionReason>  $reasonCodes
     */
    public static function configurationError(array $reasonCodes, PriceResolutionTrace $trace, PriceResolutionFailure $failure): self
    {
        return new self(PriceResolutionStatus::ConfigurationError, null, $reasonCodes, $trace, $failure);
    }

    public function toResolvedPrice(): ResolvedPrice
    {
        return match ($this->status) {
            PriceResolutionStatus::Resolved => $this->price
                ?? throw new LogicException('Resolved status requires a price.'),
            PriceResolutionStatus::Unavailable,
            PriceResolutionStatus::ConfigurationError => throw $this->restoreException(),
        };
    }

    public function restoreException(): RuntimeException
    {
        $failure = $this->failure
            ?? throw new LogicException('Failure result requires failure context.');

        return match ($this->status) {
            PriceResolutionStatus::Unavailable => new PriceNotAvailableException($failure->message),
            PriceResolutionStatus::ConfigurationError => new PriceListConfigurationException($failure->message),
            PriceResolutionStatus::Resolved => throw new LogicException('Resolved result has no exception.'),
        };
    }
}
