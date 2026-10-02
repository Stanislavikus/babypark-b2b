<?php

namespace App\Support\Filament;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Contracts\HasSchemas;

/**
 * Clears stale inline validation errors after the user fixes a field.
 *
 * Filament/Livewire do not re-run field validation on change by default.
 * Pair live() with validateOnly() in afterStateUpdated — only on fields
 * where a failed submit leaves a stale error until the value is corrected.
 */
final class RevalidatesOnUpdate
{
    public static function apply(Component $field): Component
    {
        return $field
            ->live()
            ->afterStateUpdated(function (HasSchemas $livewire, Component $component): void {
                $livewire->validateOnly($component->getStatePath());
            });
    }

    /**
     * @param  callable(Set $set): void  $reset
     */
    public static function applyWithReset(Component $field, callable $reset): Component
    {
        return $field
            ->live()
            ->afterStateUpdated(function (HasSchemas $livewire, Component $component, Set $set) use ($reset): void {
                $reset($set);
                $livewire->validateOnly($component->getStatePath());
            });
    }
}
