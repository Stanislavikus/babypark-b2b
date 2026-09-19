<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\HasMarginFormatToggle;
use App\Filament\Resources\ProductResource;
use App\Services\Sync\ProductChannelSelectionService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class ListProducts extends ListRecords
{
    use HasMarginFormatToggle;

    protected static string $resource = ProductResource::class;

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
            CreateAction::make(),
        ];
    }
}
