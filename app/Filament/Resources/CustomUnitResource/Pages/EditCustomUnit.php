<?php

namespace App\Filament\Resources\CustomUnitResource\Pages;

use App\Filament\Resources\CustomUnitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomUnit extends EditRecord
{
    protected static string $resource = CustomUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Hapus')
                ->hidden(fn () => $this->record->usageCount() > 0),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
