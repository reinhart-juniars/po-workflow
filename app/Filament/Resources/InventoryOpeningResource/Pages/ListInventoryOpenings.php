<?php

namespace App\Filament\Resources\InventoryOpeningResource\Pages;

use App\Filament\Resources\InventoryOpeningResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryOpenings extends ListRecords
{
    protected static string $resource = InventoryOpeningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
