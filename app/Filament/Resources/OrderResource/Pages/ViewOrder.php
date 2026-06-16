<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Contractor;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\MaxWidth;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contractor_info')
                ->label('Контрагент')
                ->icon('heroicon-o-building-office-2')
                ->color('info')
                ->modalHeading(fn () => $this->record->contractor?->name ?? 'Контрагент')
                ->modalWidth(MaxWidth::Medium)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрити')
                ->infolist(
                    fn (Infolist $infolist) => $infolist
                        ->record($this->record->contractor)
                        ->schema([
                            TextEntry::make('name')
                                ->label('Назва')
                                ->weight(\Filament\Support\Enums\FontWeight::Bold),
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
