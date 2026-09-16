<?php

namespace App\Filament\Resources\ProductTypeResource\Pages;

use App\Filament\Resources\ProductTypeResource;
use App\Models\User;
use App\Services\ProductStructure\ProductTypeForkService;
use App\Support\Workspace\WorkspaceContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProductTypes extends ListRecords
{
    protected static string $resource = ProductTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Створити власний тип'),
            Action::make('forkBasic')
                ->label('Створити власний тип на основі Basic Product')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => ProductTypeResource::canCreate())
                ->schema([
                    TextInput::make('code')
                        ->label('Код')
                        ->required()
                        ->alphaDash()
                        ->maxLength(255),
                    TextInput::make('label_uk')
                        ->label('Назва (UK)')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('label_en')
                        ->label('Назва (EN)')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    app(ProductTypeForkService::class)->fromBasic(
                        $actor,
                        app(WorkspaceContext::class)->current(),
                        (string) $data['code'],
                        ['uk' => (string) $data['label_uk'], 'en' => (string) $data['label_en']],
                    );

                    Notification::make()
                        ->success()
                        ->title('Власний тип створено з Basic Product')
                        ->send();
                }),
        ];
    }
}
