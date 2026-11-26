<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\HasMarginFormatToggle;
use App\Filament\Resources\ProductResource;
use App\Services\Sync\ProductChannelSelectionService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Js;
use Livewire\Attributes\Url;

class ListProducts extends ListRecords
{
    use HasMarginFormatToggle;

    protected static string $resource = ProductResource::class;

    public function mount(): void
    {
        parent::mount();

        $key = Js::from(ProductResource::LIST_REFRESH_STORAGE_KEY);
        $this->js("(() => { const key = {$key}; const slot = '__babyparkProductsRefreshHandler'; if (window[slot]) { window.removeEventListener('storage', window[slot]); } window[slot] = (event) => { if (event.key === key) { window.location.reload(); } }; window.addEventListener('storage', window[slot]); })();");
    }

    #[Url(as: 'channel')]
    public ?string $channelContext = null;

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->channelContext === null || $this->channelContext === '') {
            return null;
        }

        $label = app(ProductChannelSelectionService::class)->labelForConfiguration(
            app(WorkspaceContext::class)->current(),
            $this->channelContext,
        );

        return $label !== null
            ? __('product_channels.products_context', ['channel' => $label])
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Створити товар')
                ->url(fn (): string => ProductResource::getUrl(
                    'create',
                    ProductResource::listContextParameters(),
                )),
            Action::make('import_products')
                ->label('Імпортувати товари')
                ->icon('heroicon-o-arrow-up-tray')
                ->disabled()
                ->tooltip('Імпорт Excel / CSV буде підключено окремим етапом.'),
        ];
    }
}
