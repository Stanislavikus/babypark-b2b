<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview_as_customer')
                ->label('Перегляд як клієнт')
                ->icon('heroicon-o-eye')
                ->url(fn (): string => CustomerResource::getUrl('preview', ['record' => $this->record])),
            Actions\EditAction::make(),
        ];
    }
}
