<?php

namespace App\Filament\Menu\Resources\MasterMenuResource\Pages;

use App\Filament\Menu\Resources\MasterMenuResource;
use Filament\Resources\Pages\EditRecord;

class EditMasterMenu extends EditRecord
{
    protected static string $resource = MasterMenuResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Ditautkan ke resep = masuk alur resep (sama dengan RecipeResource::syncProducts).
        if (filled($data['recipe_id'] ?? null)) {
            $data['needs_recipe'] = true;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
