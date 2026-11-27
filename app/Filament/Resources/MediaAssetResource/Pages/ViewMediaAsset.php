<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Enums\MediaAssetType;
use App\Filament\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaAssetLibraryReadService;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Media\Exceptions\MediaAssetLifecycleException;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Js;

class ViewMediaAsset extends ViewRecord
{
    protected static string $resource = MediaAssetResource::class;

    public function getTitle(): string
    {
        return MediaAssetResource::displayName($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close_page')
                ->label('Закрити')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->action(fn (): mixed => $this->closeCurrentTab(MediaAssetResource::getUrl('index'))),
            Action::make('delete_asset')
                ->label('Видалити')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->canMutateLifecycleAsset())
                ->requiresConfirmation()
                ->modalHeading('Видалити Asset?')
                ->modalDescription(fn (): string => $this->deleteDescription())
                ->modalSubmitActionLabel('Видалити')
                ->action(function (Action $action): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User || ! $this->canManageProducts()) {
                        throw new AuthorizationException('This action is unauthorized.');
                    }

                    try {
                        app(MediaAssetLifecycleService::class)->deleteUnusedOriginal(
                            $actor,
                            app(WorkspaceContext::class)->current(),
                            $this->record,
                        );
                    } catch (MediaAssetLifecycleException $e) {
                        Notification::make()
                            ->warning()
                            ->title('Asset не видалено')
                            ->body($e->getMessage())
                            ->duration(8000)
                            ->send();

                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Asset видалено')
                        ->send();

                    $this->redirect(MediaAssetResource::getUrl('index'));
                }),
        ];
    }

    private function deleteDescription(): string
    {
        $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($this->record);

        if (array_sum($usage) > 0) {
            return 'Asset зараз використовується і не буде видалений. '.$this->usageSummary($usage).'. Спочатку приберіть або перепризначте ці використання.';
        }

        return 'Asset буде фізично видалено з Master. Керований Original-файл буде очищено після успішної фіксації змін у базі даних.';
    }

    /**
     * @param  array{products:int,variants:int,brands:int,derivatives:int}  $usage
     */
    private function usageSummary(array $usage): string
    {
        return sprintf(
            'Товарів: %d · Варіантів: %d · Брендів: %d · Похідних версій: %d',
            $usage['products'],
            $usage['variants'],
            $usage['brands'],
            $usage['derivatives'],
        );
    }

    private function closeCurrentTab(string $fallbackUrl): null
    {
        $fallback = Js::from($fallbackUrl);

        $this->js("window.close(); setTimeout(() => { if (! window.closed) { window.location.href = {$fallback}; } }, 100);");

        return null;
    }

    private function canMutateLifecycleAsset(): bool
    {
        return $this->canManageProducts()
            && $this->record instanceof MediaAsset
            && $this->record->isOriginal()
            && $this->record->asset_type === MediaAssetType::Image;
    }

    private function canManageProducts(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(WorkspaceAuthorization::class)->allows(
                $actor,
                app(WorkspaceContext::class)->current(),
                WorkspacePermissions::MANAGE_PRODUCTS,
            );
    }
}
