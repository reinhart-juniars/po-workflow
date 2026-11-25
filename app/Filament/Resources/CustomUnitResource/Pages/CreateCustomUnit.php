<?php

namespace App\Filament\Resources\CustomUnitResource\Pages;

use App\Filament\Resources\CustomUnitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomUnit extends CreateRecord
{
    protected static string $resource = CustomUnitResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
