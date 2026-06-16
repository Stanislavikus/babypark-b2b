<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\MaxWidth;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // Not rendered in the header (->hidden()), but registered in cachedActions so
            // that the infolist thumbnail can trigger it via Alpine: $wire.mountAction('view_image').
            Actions\Action::make('view_image')
                ->hidden()
                ->modalHeading(fn () => $this->record->name)
                ->modalWidth(MaxWidth::Large)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрити')
                ->modalContent(fn () => view(
                    'filament.product-image-lightbox',
                    [
                        'url' => ProductResource::firstImage($this->record),
                        'alt' => $this->record->name,
                    ]
                )),
        ];
    }
}
