<?php

namespace App\Filament\Resources\ProductionWorkerResource\Pages;

use App\Filament\Resources\ProductionWorkerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionWorker extends CreateRecord
{
    protected static string $resource = ProductionWorkerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
