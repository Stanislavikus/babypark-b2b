<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;

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
                ->modalWidth(Width::Medium)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрити')
                ->schema(
                    fn (Schema $schema) => $schema
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
            EditAction::make(),
        ];
    }
}
