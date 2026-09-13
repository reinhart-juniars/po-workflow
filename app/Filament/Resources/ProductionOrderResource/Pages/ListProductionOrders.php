<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListProductionOrders extends ListRecords
{
    protected static string $resource = ProductionOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductionOrderResource::generateFromSpkAction(),
            Actions\CreateAction::make()->label('Susun Manual'),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'terbuka' => Tab::make('Terbuka')
                ->modifyQueryUsing(fn ($query) => $query->open())
                ->badge(ProductionOrder::query()->open()->count()),

            'selesai' => Tab::make('Selesai')
                ->modifyQueryUsing(fn ($query) => $query->completed()),

            'semua' => Tab::make('Semua'),
        ];
    }
}
