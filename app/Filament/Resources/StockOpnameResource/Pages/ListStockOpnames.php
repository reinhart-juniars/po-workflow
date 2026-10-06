<?php

namespace App\Filament\Resources\StockOpnameResource\Pages;

use App\Exports\ValueEntriesExport;
use App\Filament\Concerns\HasValueEntryExcelActions;
use App\Filament\Resources\StockOpnameResource;
use App\Imports\ValueEntriesImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockOpnames extends ListRecords
{
    use HasValueEntryExcelActions;

    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            ...$this->excelActions(
                'stock-opname',
                fn () => ValueEntriesExport::opname(),
                fn (?int $userId) => ValueEntriesImport::opname($userId),
            ),
        ];
    }
}
