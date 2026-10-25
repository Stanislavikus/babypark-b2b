<?php

namespace App\Filament\Support;

use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

/**
 * Approved Filament/Livewire Media source area.
 *
 * The supplied controls own their native actions and persistence; this
 * presentation component never uploads, ingests, or reassigns an Original.
 */
final class MediaSourceArea
{
    /**
     * @param  array<int, \Filament\Schemas\Components\Component>  $controls
     */
    public static function make(array $controls, string $extraClass = ''): Group
    {
        return Group::make([
            Text::make(new HtmlString('<svg aria-hidden="true" viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="16" height="16" rx="2"/><circle cx="8" cy="10" r="1.3"/><path d="m5 18 4.5-5 3.5 3 2.5-3 2.5 3"/><path d="M20 1v8m-4-4h8"/></svg>'))
                ->extraAttributes(['class' => 'bp-brand-media-icon']),
            Text::make(__('media_ui.drop_heading'))
                ->weight(FontWeight::SemiBold)
                ->extraAttributes(['class' => 'bp-brand-media-heading']),
            Text::make(__('media_ui.drop_subheading'))
                ->extraAttributes(['class' => 'bp-brand-media-subheading']),
            ...$controls,
            Text::make(__('media_ui.upload_hint'))
                ->extraAttributes(['class' => 'bp-brand-media-format']),
        ])
            ->extraAttributes(['class' => trim('bp-brand-media-dropzone '.$extraClass)])
            ->columnSpanFull();
    }
}
