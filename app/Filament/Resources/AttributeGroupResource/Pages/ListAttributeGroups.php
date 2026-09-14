<?php

namespace App\Filament\Resources\AttributeGroupResource\Pages;

use App\Filament\Resources\AttributeGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributeGroups extends ListRecords
{
    protected static string $resource = AttributeGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Створити групу')];
    }
}
