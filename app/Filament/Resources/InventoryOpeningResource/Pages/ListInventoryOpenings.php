<?php

namespace App\Filament\Resources\InventoryOpeningResource\Pages;

use App\Exports\ValueEntriesExport;
use App\Filament\Concerns\HasValueEntryExcelActions;
use App\Filament\Resources\InventoryOpeningResource;
use App\Imports\ValueEntriesImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryOpenings extends ListRecords
{
    use HasValueEntryExcelActions;

    protected static string $resource = InventoryOpeningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            ...$this->excelActions(
                'saldo-awal',
                fn () => ValueEntriesExport::opening(),
                fn (?int $userId) => ValueEntriesImport::opening($userId),
            ),
        ];
    }
}
