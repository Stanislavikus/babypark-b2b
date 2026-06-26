<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\HasProductLightbox;
use App\Filament\Resources\ProductResource;
use App\Support\ProductFields\ProductImageStorage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\View\View;

class EditProduct extends EditRecord
{
    use HasProductLightbox;

    protected static string $resource = ProductResource::class;

    public static string|Alignment $formActionsAlignment = Alignment::End;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->extraAttributes(['data-bp-align-ref' => 'header-view']),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            $this->getSaveFormAction(),
        ];
    }

    public function getFooter(): ?View
    {
        return view('filament.partials.product-edit-form-actions-align');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $images = $data['images'] ?? [];
        $firstUrl = is_array($images) ? ($images[0] ?? null) : null;

        if ($path = ProductImageStorage::pathFromUrl($firstUrl)) {
            $data['image_upload'] = [$path];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('image_upload', $data)) {
            $upload = $data['image_upload'];
            $path = is_array($upload) ? ($upload[0] ?? null) : $upload;

            if (filled($path)) {
                $data['images'] = [ProductImageStorage::urlFromPath($path)];
            } elseif ($this->record) {
                $existingUrl = self::firstImage($this->record);
                $data['images'] = filled($existingUrl) && ProductImageStorage::pathFromUrl($existingUrl) === null
                    ? $this->record->images
                    : [];
            } else {
                $data['images'] = [];
            }

            unset($data['image_upload']);
        }

        unset(
            $data['variant_ean_display'],
            $data['admin_stock_status_display'],
            $data['admin_rrp_display'],
            $data['admin_margin_display'],
            $data['admin_status_display'],
        );

        return $data;
    }
}
