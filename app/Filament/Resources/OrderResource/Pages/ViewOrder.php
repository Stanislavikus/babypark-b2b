<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\MaxWidth;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customer_info')
                ->label('Клієнт')
                ->icon('heroicon-o-building-office-2')
                ->color('info')
                ->modalHeading(fn () => $this->record->customer?->name ?? 'Клієнт')
                ->modalWidth(MaxWidth::Medium)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрити')
                ->infolist(
                    fn (Infolist $infolist) => $infolist
                        ->record($this->record->customer)
                        ->schema([
                            TextEntry::make('name')
                                ->label('Назва')
                                ->weight(FontWeight::Bold),
                            TextEntry::make('manager_name')
                                ->label('Менеджер')
                                ->placeholder('—'),
                            TextEntry::make('manager_phone')
                                ->label('Телефон')
                                ->placeholder('—')
                                ->icon('heroicon-m-phone'),
                            TextEntry::make('email')
                                ->label('Email')
                                ->placeholder('—')
                                ->icon('heroicon-m-envelope'),
                        ])
                        ->columns(2)
                ),
            Actions\EditAction::make(),
        ];
    }
}
