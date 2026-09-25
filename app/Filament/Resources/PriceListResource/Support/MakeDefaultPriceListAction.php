<?php

namespace App\Filament\Resources\PriceListResource\Support;

use App\Enums\PriceListStatus;
use App\Models\PriceList;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class MakeDefaultPriceListAction
{
    public static function makeTableAction(): Action
    {
        return Action::make('makeDefault')
            ->label('Зробити списком за замовчуванням')
            ->icon('heroicon-o-star')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Зробити списком за замовчуванням')
            ->modalDescription(fn (PriceList $record): string => self::confirmationMessage($record))
            ->visible(fn (PriceList $record): bool => self::isVisible($record))
            ->action(fn (PriceList $record) => self::execute($record));
    }

    public static function makeHeaderAction(): Action
    {
        return Action::make('makeDefault')
            ->label('Зробити списком за замовчуванням')
            ->icon('heroicon-o-star')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Зробити списком за замовчуванням')
            ->modalDescription(fn (PriceList $record): string => self::confirmationMessage($record))
            ->visible(fn (PriceList $record): bool => self::isVisible($record))
            ->action(fn (PriceList $record) => self::execute($record));
    }

    private static function isVisible(PriceList $record): bool
    {
        return ! $record->is_default && $record->status === PriceListStatus::Active;
    }

    private static function confirmationMessage(PriceList $record): string
    {
        $currentDefault = PriceList::query()
            ->where('workspace_id', $record->workspace_id)
            ->where('is_default', true)
            ->first();

        if ($currentDefault === null) {
            return 'Цей прайс-лист стане списком за замовчуванням для компанії.';
        }

        return 'Цей прайс-лист замінить поточний список за замовчуванням «'.$currentDefault->name.'».';
    }

    private static function execute(PriceList $record): void
    {
        DB::transaction(function () use ($record): void {
            PriceList::withoutWorkspaceScope()
                ->where('workspace_id', $record->workspace_id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $record->update(['is_default' => true]);
        });

        Notification::make()
            ->success()
            ->title('Список за замовчуванням оновлено')
            ->body('«'.$record->name.'» тепер є списком за замовчуванням.')
            ->send();
    }
}
