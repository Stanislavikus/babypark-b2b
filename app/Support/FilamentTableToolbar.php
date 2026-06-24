<?php

namespace App\Support;

use Filament\View\PanelsRenderHook;

class FilamentTableToolbar
{
    public static function stylesRenderHook(): \Closure
    {
        return fn (): string => view('filament.partials.table-toolbar-overrides')->render();
    }

    public static function stylesHookName(): string
    {
        return PanelsRenderHook::STYLES_AFTER;
    }
}
