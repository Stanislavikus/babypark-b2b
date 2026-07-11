<?php

namespace App\Filament\Resources\TagResource\Support;

use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Illuminate\Support\Facades\DB;

class GuardedDeleteTagAction
{
    public static function makeTableAction(): TableDeleteAction
    {
        return TableDeleteAction::make()
            ->action(function (TableDeleteAction $action, Tag $record): void {
                self::deleteOrNotify($action, $record);
            });
    }

    public static function makeHeaderAction(): DeleteAction
    {
        return DeleteAction::make()
            ->action(function (DeleteAction $action, Tag $record): void {
                self::deleteOrNotify($action, $record);
            });
    }

    private static function deleteOrNotify(DeleteAction|TableDeleteAction $action, Tag $record): void
    {
        try {
            DB::transaction(function () use ($record): void {
                $lockedTag = Tag::withoutWorkspaceScope()
                    ->whereKey($record->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $count = DB::table('product_tag')
                    ->where('tag_id', $lockedTag->id)
                    ->count();

                if ($count > 0) {
                    throw new TagInUseException($count);
                }

                $lockedTag->delete();
            });
        } catch (TagInUseException $e) {
            Notification::make()
                ->danger()
                ->title('Неможливо видалити тег')
                ->body("Тег використовується у {$e->productCount} товарах. Спочатку видаліть його з товарів.")
                ->send();

            $action->halt();

            return;
        }

        $action->success();
    }
}
