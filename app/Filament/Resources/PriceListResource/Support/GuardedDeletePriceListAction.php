<?php

namespace App\Filament\Resources\PriceListResource\Support;

use App\Models\PriceList;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;

class GuardedDeletePriceListAction
{
    public static function makeTableAction(): TableDeleteAction
    {
        return TableDeleteAction::make()
            ->before(function (TableDeleteAction $action, PriceList $record): void {
                self::guardOrCancel($action, $record);
            });
    }

    public static function makeHeaderAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, PriceList $record): void {
                self::guardOrCancel($action, $record);
            });
    }

    private static function guardOrCancel(DeleteAction|TableDeleteAction $action, PriceList $record): void
    {
        $reason = PriceListGuard::deleteBlockReason($record);

        if ($reason['allowed']) {
            return;
        }

        Notification::make()
            ->danger()
            ->title($reason['title'])
            ->body($reason['body'])
            ->send();

        $action->cancel();
    }
}
