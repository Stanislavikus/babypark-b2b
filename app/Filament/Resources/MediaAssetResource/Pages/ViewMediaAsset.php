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
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
            Action::make('replace_asset')
                ->label('Замінити')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->canMutateLifecycleAsset())
                ->modalHeading('Замінити зображення?')
                ->modalDescription(fn (): string => $this->replaceDescription())
                ->modalSubmitActionLabel('Замінити')
                ->schema([
                    FileUpload::make('file')
                        ->label('Нове зображення')
                        ->storeFiles(false)
                        ->maxSize(20 * 1024)
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                            'image/avif',
                        ])
                        ->validationMessages([
                            'max' => 'Файл завеликий. Максимальний розмір зображення — 20 МіБ.',
                            'mimetypes' => 'Підтримуються JPEG, PNG, WebP, GIF або AVIF. SVG поки не підтримується.',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User || ! $this->canManageProducts()) {
                        throw new AuthorizationException('This action is unauthorized.');
                    }

                    $file = $data['file'] ?? null;

                    if (! $file instanceof UploadedFile) {
                        throw ValidationException::withMessages([
                            'file' => 'Оберіть нове зображення.',
                        ]);
                    }

                    try {
                        $result = app(MediaAssetLifecycleService::class)->replaceOriginal(
                            $actor,
                            app(WorkspaceContext::class)->current(),
                            $this->record,
                            $file,
                        );
                    } catch (MediaAssetLifecycleException|MediaIngestException $e) {
                        throw ValidationException::withMessages([
                            'file' => $e->getMessage(),
                        ]);
                    }

                    $this->record->refresh();
                    $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($this->record);

                    Notification::make()
                        ->success()
                        ->title($result->replaced ? 'Зображення замінено' : 'Зображення вже актуальне')
                        ->body($usage['products'] > 0 && $result->replaced
                            ? 'Asset використовується товарами. Запустіть Preview ще раз, щоб перевірити актуальне зображення перед передачею.'
                            : null)
                        ->send();
                }),
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
                            ->danger()
                            ->title('Asset не видалено')
                            ->body($e->getMessage())
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

    private function replaceDescription(): string
    {
        $usage = app(MediaAssetLibraryReadService::class)->freshUsageCounts($this->record);
        $description = 'Буде змінено цей самий Asset у всіх поточних використаннях. '.$this->usageSummary($usage).'.';

        if ($usage['derivatives'] > 0) {
            $description .= ' Заміна буде заблокована, поки існують похідні версії.';
        }

        if ($usage['products'] > 0) {
            $description .= ' Після заміни запустіть Preview ще раз для перевірки перед передачею.';
        }

        return $description;
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
