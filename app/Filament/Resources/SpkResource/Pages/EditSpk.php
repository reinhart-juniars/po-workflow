<?php

namespace App\Filament\Resources\SpkResource\Pages;

use App\Filament\Resources\SpkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpk extends EditRecord
{
    protected static string $resource = SpkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
