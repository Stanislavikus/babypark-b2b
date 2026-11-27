<?php

namespace App\Support\Media;

final class MediaSaveNotice
{
    public static function unchanged(bool $otherChangesSaved): string
    {
        return $otherChangesSaved
            ? __('media_ui.unchanged_other_saved')
            : __('media_ui.unchanged_no_changes');
    }

    public static function reused(): string
    {
        return __('media_ui.reused_notice');
    }
}
