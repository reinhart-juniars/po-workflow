<?php

namespace App\Filament\Resources\ProductionWorkerResource\Pages;

use App\Filament\Resources\ProductionWorkerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductionWorker extends EditRecord
{
    protected static string $resource = ProductionWorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Hapus'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
