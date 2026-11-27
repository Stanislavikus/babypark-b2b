<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Workspace\UserLifecycleService;
use App\Support\Workspace\Rbac\Exceptions\UserDeletionForbiddenException;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->action(function (DeleteAction $action, User $record): void {
                    try {
                        app(UserLifecycleService::class)->delete($record);
                    } catch (UserDeletionForbiddenException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Видалення заборонено')
                            ->body($exception->getMessage())
                            ->send();

                        $action->halt();

                        return;
                    }

                    $action->success();
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        return app(UserLifecycleService::class)->update($record, $data);
    }
}
