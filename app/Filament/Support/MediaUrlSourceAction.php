<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

/**
 * Shared native URL source modal. Each authoring context keeps its own
 * authorized writer and commit semantics; the modal never persists an Asset.
 */
final class MediaUrlSourceAction
{
    public static function make(string $name): Action
    {
        return Action::make($name)
            ->label(__('media_ui.add_from_url'))
            ->icon('heroicon-o-link')
            ->outlined()
            ->color('gray')
            ->modalHeading(__('media_ui.add_from_url'))
            ->modalSubmitActionLabel(__('media_ui.add_from_url'))
            ->schema([
                TextInput::make('url')
                    ->label(__('media_ui.image_url'))
                    ->placeholder('https://example.com/photo.jpg')
                    ->url()
                    ->required()
                    ->maxLength(2048),
            ]);
    }
}
