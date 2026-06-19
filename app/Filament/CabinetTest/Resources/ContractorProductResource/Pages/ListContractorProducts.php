<?php

namespace App\Filament\CabinetTest\Resources\ContractorProductResource\Pages;

use App\Filament\CabinetTest\Resources\ContractorProductResource;
use Filament\Resources\Pages\ListRecords;

class ListContractorProducts extends ListRecords
{
    protected static string $resource = ContractorProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
