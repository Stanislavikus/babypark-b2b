<?php

namespace App\Filament\Resources\ProductResource\Pages\Concerns;

use App\Filament\Resources\ProductResource;
use Filament\Actions\Action;
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;

trait HasProductPageClose
{
    #[Locked]
    public array $productListContext = [];

    protected function afterFill(): void
    {
        $this->rememberData();
    }

    protected function captureProductListContext(): void
    {
        $this->productListContext = ProductResource::listContextParameters();
    }

    protected function closeProductPageAction(): Action
    {
        return Action::make('close_page')
            ->label('Закрити')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->requiresConfirmation(fn (): bool => $this->hasUnsavedProductChanges())
            ->modalHidden(fn (): bool => ! $this->hasUnsavedProductChanges())
            ->modalHeading('Закрити без збереження?')
            ->modalDescription('Незбережені зміни товару буде втрачено.')
            ->modalSubmitActionLabel('Закрити')
            ->action(fn (): mixed => $this->closeProductPage());
    }

    private function hasUnsavedProductChanges(): bool
    {
        if (! isset($this->savedDataHash)) {
            return false;
        }

        $currentHash = md5((string) str(
            json_encode($this->data, JSON_UNESCAPED_UNICODE),
        )->replace('\\', ''));

        return $this->savedDataHash !== $currentHash;
    }

    private function closeProductPage(): null
    {
        $fallback = Js::from(ProductResource::getUrl('index', $this->productListContext));
        $refreshKey = Js::from(ProductResource::LIST_REFRESH_STORAGE_KEY);

        $this->rememberData();
        $this->unmountAction();
        $this->js("(() => { try { \$wire.savedDataHash = window.jsMd5(JSON.stringify(\$wire.data).split(String.fromCharCode(92)).join('')); } catch (e) {} try { localStorage.setItem({$refreshKey}, String(Date.now()) + ':' + String(Math.random())); } catch (e) {} if (window.opener && ! window.opener.closed) { window.close(); } if (! window.closed) { window.location.href = {$fallback}; } })();");

        return null;
    }
}
