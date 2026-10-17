<?php

namespace App\Enums;

enum AttributeScope: string
{
    case System = 'system';
    case PlatformLibrary = 'platform_library';
    case WorkspaceCustom = 'workspace_custom';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Системне',
            self::PlatformLibrary => 'Бібліотека',
            self::WorkspaceCustom => 'Моє поле',
        };
    }
}
