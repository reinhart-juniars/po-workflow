<?php

namespace App\Filament\Resources\RecipeResource\Pages;

use App\Filament\Resources\RecipeResource;
use App\Models\Recipe;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Resep'),
        ];
    }

    /**
     * Menu utama dan sub-menu dipisahkan di sini, bukan menjadi dua modul.
     *
     * Dua tab terakhir adalah daftar kerja: menu yang belum terhubung ke produk
     * penjualan, dan resep yang masih punya bahan belum tertaut. Keduanya yang
     * menahan HPP berbasis resep dari bisa dipercaya.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'semua' => Tab::make('Semua'),

            'utama' => Tab::make('Menu Utama')
                ->modifyQueryUsing(fn ($query) => $query->utama())
                ->badge(Recipe::query()->utama()->count()),

            'sub' => Tab::make('Sub-Menu')
                ->modifyQueryUsing(fn ($query) => $query->sub())
                ->badge(Recipe::query()->sub()->count()),

            'belum_dipetakan' => Tab::make('Belum Dipetakan')
                ->modifyQueryUsing(fn ($query) => $query->utama()->unmapped())
                ->badge(Recipe::query()->utama()->unmapped()->count())
                ->badgeColor('warning'),

            'bahan_yatim' => Tab::make('Ada Bahan Belum Tertaut')
                ->modifyQueryUsing(fn ($query) => $query->whereHas('items', fn ($items) => $items->unmatched()))
                ->badge(Recipe::query()->whereHas('items', fn ($items) => $items->unmatched())->count())
                ->badgeColor('danger'),
        ];
    }
}
