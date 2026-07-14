<?php

namespace App\Services\Pricing;

readonly class PriceDisplayPresentation
{
    public function __construct(
        public string $primaryLine,
        public ?string $secondaryLine = null,
        public ?string $tertiaryLine = null,
        public string $decisionPathLabel = '',
    ) {}

    public function compactLabel(): string
    {
        return $this->primaryLine;
    }

    public function fullLabel(): string
    {
        return collect([$this->primaryLine, $this->secondaryLine, $this->tertiaryLine])
            ->filter()
            ->implode(' / ');
    }
}
