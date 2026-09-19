<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\Support\GuardedDeletePriceListAction;
use App\Filament\Resources\PriceListResource\Support\MakeDefaultPriceListAction;
use App\Filament\Resources\PriceListResource\Support\PriceListGuard;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPriceList extends EditRecord
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MakeDefaultPriceListAction::makeHeaderAction(),
            GuardedDeletePriceListAction::makeHeaderAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function beforeSave(): void
    {
        $newStatus = $this->data['status'] ?? $this->record->status->value;

        if (! PriceListGuard::canDeactivateTo($this->record, $newStatus)) {
            $reason = PriceListGuard::deactivateBlockReason($this->record);

            Notification::make()
                ->danger()
                ->title($reason['title'])
                ->body($reason['body'])
                ->send();

            $this->halt();
        }
    }
}
