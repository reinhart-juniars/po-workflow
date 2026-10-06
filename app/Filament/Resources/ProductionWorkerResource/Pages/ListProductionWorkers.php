<?php

namespace App\Filament\Resources\ProductionWorkerResource\Pages;

use App\Filament\Resources\ProductionWorkerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductionWorkers extends ListRecords
{
    protected static string $resource = ProductionWorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Pelaksana'),
        ];
    }
}
