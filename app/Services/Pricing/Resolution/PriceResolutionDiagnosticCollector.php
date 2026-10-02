<?php

namespace App\Services\Pricing\Resolution;

final class PriceResolutionDiagnosticCollector
{
    public static int $instantiationCount = 0;

    /** @var list<PriceResolutionReason> */
    private array $reasonCodes = [];

    /** @var list<PriceResolutionStep> */
    private array $steps = [];

    public function __construct()
    {
        self::$instantiationCount++;
    }

    public static function resetInstantiationCount(): void
    {
        self::$instantiationCount = 0;
    }

    public function addStep(PriceResolutionStep $step): void
    {
        $this->steps[] = $step;
    }

    public function addReasonCode(PriceResolutionReason $reason): void
    {
        if (! in_array($reason, $this->reasonCodes, true)) {
            $this->reasonCodes[] = $reason;
        }
    }

    /**
     * @param  list<PriceResolutionSource>  $sources
     */
    public function addNotCheckedSteps(array $sources): void
    {
        foreach ($sources as $source) {
            $this->addStep(new PriceResolutionStep(
                source: $source,
                status: PriceResolutionStepStatus::NotChecked,
                reason: PriceResolutionReason::PreviousSourceResolved,
            ));
            $this->addReasonCode(PriceResolutionReason::PreviousSourceResolved);
        }
    }

    public function buildTrace(): PriceResolutionTrace
    {
        return new PriceResolutionTrace($this->steps);
    }

    /**
     * @return list<PriceResolutionReason>
     */
    public function reasonCodes(): array
    {
        return $this->reasonCodes;
    }
}
