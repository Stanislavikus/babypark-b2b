<?php

namespace App\Filament\Concerns;

trait HasMarginFormatToggle
{
    /** Margin column display format: 'percent' or 'uah' */
    public string $marginFormat = 'percent';

    public function toggleMarginFormat(): void
    {
        $this->marginFormat = $this->marginFormat === 'percent' ? 'uah' : 'percent';
    }
}
