<?php

namespace App\Support\Workspace\Rbac\Concerns;

use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessLockoutException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessMutationRejectedException;
use App\Support\Workspace\Rbac\Exceptions\WorkspaceAccessUnauthorizedException;
use Filament\Notifications\Notification;
use Throwable;

trait HandlesWorkspaceAccessExceptions
{
    protected function notifyWorkspaceAccessSuccess(string $messageKey): void
    {
        Notification::make()
            ->title(__($messageKey))
            ->success()
            ->send();
    }

    protected function handleWorkspaceAccessException(Throwable $exception): void
    {
        if ($exception instanceof WorkspaceAccessUnauthorizedException) {
            Notification::make()
                ->title(__('workspace_access.errors.'.$exception->userMessageKey()))
                ->danger()
                ->send();

            abort(403);
        }

        if ($exception instanceof WorkspaceAccessLockoutException
            || $exception instanceof WorkspaceAccessMutationRejectedException) {
            Notification::make()
                ->title(__('workspace_access.errors.'.$exception->userMessageKey()))
                ->danger()
                ->send();

            return;
        }

        throw $exception;
    }
}
