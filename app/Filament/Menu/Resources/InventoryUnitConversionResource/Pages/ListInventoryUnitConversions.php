<?php

namespace App\Filament\Menu\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Menu\Resources\InventoryUnitConversionResource;
use App\Services\MissingUnitConversionScanner;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryUnitConversions extends ListRecords
{
    protected static string $resource = InventoryUnitConversionResource::class;

    protected function getHeaderActions(): array
    {
        $missing = app(MissingUnitConversionScanner::class)->summary();

        return [
            Actions\Action::make('butuh_aturan')
                ->label('Butuh Aturan ('.number_format($missing['pasangan'], 0, ',', '.').')')
                ->icon('heroicon-m-exclamation-triangle')
                ->color($missing['pasangan'] > 0 ? 'warning' : 'gray')
                ->url(InventoryUnitConversionResource::getUrl('missing')),

            Actions\CreateAction::make()->label('Tambah Aturan'),
        ];
    }
}
