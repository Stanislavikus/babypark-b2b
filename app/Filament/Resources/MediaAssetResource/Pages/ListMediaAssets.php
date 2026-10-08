<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use App\Models\User;
use App\Services\Media\OriginalImageIngestService;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Media\Exceptions\MediaIngestException;
use App\Support\Workspace\WorkspaceContext;
use App\Support\Workspace\WorkspacePermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ListMediaAssets extends ListRecords
{
    protected static string $resource = MediaAssetResource::class;

    #[Url(as: 'layout')]
    public string $assetLayout = 'grid';

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->assetLayout, ['grid', 'list'], true)) {
            $this->assetLayout = 'grid';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggle_layout')
                ->label(fn (): string => $this->assetLayout === 'grid' ? 'Список' : 'Сітка')
                ->icon(fn (): string => $this->assetLayout === 'grid'
                    ? 'heroicon-o-list-bullet'
                    : 'heroicon-o-squares-2x2')
                ->color('gray')
                ->action(function (): void {
                    $this->assetLayout = $this->assetLayout === 'grid' ? 'list' : 'grid';
                }),
            Action::make('upload_assets')
                ->label('Завантажити')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => $this->canManageProducts())
                ->modalHeading('Завантажити Original')
                ->modalDescription('Original зберігається без resize та повторного кодування. Максимум 20 МіБ і 25 МП на файл.')
                ->schema([
                    FileUpload::make('files')
                        ->label('Зображення')
                        ->multiple()
                        ->storeFiles(false)
                        ->appendFiles()
                        ->maxSize(20 * 1024)
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                            'image/avif',
                        ])
                        ->validationMessages([
                            'max' => 'Файл завеликий. Максимальний розмір Original — 20 МіБ.',
                            'mimetypes' => 'Підтримуються JPEG, PNG, WebP, GIF або AVIF. SVG поки не підтримується.',
                        ])
                        ->required()
                        ->helperText('JPEG, PNG, WebP, GIF або AVIF. SVG поки не підтримується й буде відхилено з поясненням.'),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User || ! $this->canManageProducts()) {
                        throw new AuthorizationException('This action is unauthorized.');
                    }

                    $files = array_values(array_filter(
                        $data['files'] ?? [],
                        fn ($file): bool => $file instanceof UploadedFile,
                    ));

                    if ($files === []) {
                        throw ValidationException::withMessages([
                            'files' => 'Оберіть хоча б одне зображення.',
                        ]);
                    }

                    try {
                        $assets = app(OriginalImageIngestService::class)->ingestStandaloneMany(
                            $actor,
                            app(WorkspaceContext::class)->current(),
                            $files,
                        );
                    } catch (MediaIngestException $e) {
                        throw ValidationException::withMessages([
                            'files' => $e->getMessage(),
                        ]);
                    }

                    Notification::make()
                        ->success()
                        ->title(count($assets) === 1 ? 'Asset збережено' : 'Assets збережено')
                        ->body('Додано або повторно використано: '.count($assets))
                        ->send();
                }),
        ];
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
