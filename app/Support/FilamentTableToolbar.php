<?php

namespace App\Support;

use Filament\View\PanelsRenderHook;

class FilamentTableToolbar
{
    public static function stylesRenderHook(): \Closure
    {
        return fn (): string => '<style>'
            .file_get_contents(resource_path('css/design-tokens.css'))
            .'</style>'
            .view('filament.partials.table-toolbar-overrides')->render();
    }

    public static function stylesHookName(): string
    {
        return PanelsRenderHook::STYLES_AFTER;
    }
}
