<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\ProductLifecycleStatus;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\Concerns\HasProductPageClose;
use App\Services\Catalog\MasterProductDraftCreator;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use HasProductPageClose;

    protected static string $resource = ProductResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function mount(): void
    {
        parent::mount();

        $this->captureProductListContext();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->closeProductPageAction(),
        ];
    }

    public function getTitle(): string
    {
        return 'Новий товар';
    }

    public function getSubheading(): ?string
    {
        return 'Для чернетки достатньо заповнити лише «Назва». Решту даних можна додати пізніше.';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['lifecycle_status'] = ProductLifecycleStatus::tryFrom(
            (string) data_get($this->data, 'master_lifecycle_status'),
        )?->value ?? ProductLifecycleStatus::Draft->value;

        return app(MasterProductDraftCreator::class)->create(
            app(WorkspaceContext::class)->current(),
            $data,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return null;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Зберегти');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Зберегти й додати ще товар');
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', [
            'record' => $this->record,
            ...$this->productListContext,
        ]);
    }
}
