<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Js;

class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->installCrossTabRefreshListener();
    }

    private function installCrossTabRefreshListener(): void
    {
        $key = Js::from(BrandResource::LIST_REFRESH_STORAGE_KEY);

        $this->js("(() => { const key = {$key}; const slot = '__babyparkBrandsRefreshHandler'; if (window[slot]) { window.removeEventListener('storage', window[slot]); } window[slot] = (event) => { if (event.key === key) { window.location.reload(); } }; window.addEventListener('storage', window[slot]); })();");
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
