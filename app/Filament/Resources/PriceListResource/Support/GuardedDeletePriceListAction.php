<?php

namespace App\Filament\Resources\PriceListResource\Support;

use App\Models\PriceList;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

class GuardedDeletePriceListAction
{
    public static function makeTableAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, PriceList $record): void {
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

    private static function guardOrCancel(DeleteAction $action, PriceList $record): void
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
