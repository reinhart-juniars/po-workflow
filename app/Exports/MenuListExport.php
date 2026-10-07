<?php

namespace App\Exports;

use App\Filament\Menu\Resources\RecipeResource;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Daftar Menu beserta HPP dan status profit terhadap harga jual nyata --
 * padanan export "Daftar Menu" di Master Menu Revamp. Berbeda dengan
 * RecipesExport (struktur resep untuk diimpor ulang), berkas ini untuk dibaca.
 */
class MenuListExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return [
            'Nama Menu', 'Jenis', 'Kategori', 'Hasil', 'HPP / Hasil', 'OHC', 'Profit Hitungan',
            'Harga Jual Hitungan', 'Target Harga Jual', 'HPP + OHC', 'Profit Nyata', 'Profit %', 'Margin %',
            'Status Profit', 'Keterangan',
        ];
    }

    public function collection(): Collection
    {
        return Recipe::query()
            ->where('is_active', true)
            ->orderBy('jenis')
            ->orderBy('name')
            ->get()
            ->map(function (Recipe $recipe) {
                $cost = RecipeResource::costOf($recipe);

                return [
                    $recipe->name,
                    Recipe::jenisOptions()[$recipe->jenis] ?? $recipe->jenis,
                    $recipe->kategori,
                    RecipeResource::formatQty($recipe->yield_qty, '.').' '.$recipe->yield_unit,
                    $cost['hpp_per_yield'],
                    $cost['ohc'],
                    $cost['profit'],
                    $cost['harga_jual'],
                    $recipe->target_price !== null ? (float) $recipe->target_price : null,
                    $cost['total_biaya'],
                    $cost['profit_aktual'],
                    round($cost['profit_pct_aktual'] * 100, 2),
                    round($cost['margin_pct_aktual'] * 100, 2),
                    $cost['profit_ok'] ? 'OK' : 'DI BAWAH TARGET',
                    RecipeResource::costStatus($recipe),
                ];
            });
    }
}
