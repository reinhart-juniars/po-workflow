<?php

namespace App\Filament\Resources\InventoryUnitConversionResource\Pages;

use App\Filament\Resources\InventoryUnitConversionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInventoryUnitConversion extends EditRecord
{
    protected static string $resource = InventoryUnitConversionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Hapus'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
