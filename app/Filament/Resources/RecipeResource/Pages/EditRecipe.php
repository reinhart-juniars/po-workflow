<?php

namespace App\Filament\Resources\RecipeResource\Pages;

use App\Filament\Resources\RecipeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRecipe extends EditRecord
{
    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('hpp')
                ->label('Analisa HPP')
                ->icon('heroicon-m-calculator')
                ->color('gray')
                ->url(fn () => $this->getResource()::getUrl('hpp', ['record' => $this->getRecord()])),

            Actions\DeleteAction::make()->label('Hapus'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
