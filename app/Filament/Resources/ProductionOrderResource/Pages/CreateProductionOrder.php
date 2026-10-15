<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionOrder extends CreateRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Gudang tanpa production.manage hanya boleh membuat Rencana Masak, walau
        // isian toggle-nya dimanipulasi.
        if (! auth()->user()?->can('production.manage')) {
            $data['is_ready_stock'] = true;
        }

        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
