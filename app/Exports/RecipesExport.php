<?php

namespace App\Exports;

use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Export resep beserta rincian bahannya.
 *
 * Bentuknya mendatar: satu baris per bahan, kolom resepnya diulang. Itu bentuk
 * yang bisa disaring dan diurutkan di Excel, dan bentuk yang sama dengan sheet
 * asal klien -- resep bersarang tidak bisa dikerjakan orang di spreadsheet.
 *
 * File ini juga menjadi format import, jadi bahan_id dan sub_resep_id ikut
 * dibawa: tanpa keduanya, mengunggah balik hasil export akan melepas seluruh
 * tautan bahan dan mengembalikan resepnya ke daftar bahan belum cocok.
 */
class RecipesExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(
        protected ?Collection $recipes = null
    ) {}

    public function collection(): Collection
    {
        $recipes = $this->recipes ?? Recipe::query()
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')
            ->get();

        $rows = collect();

        foreach ($recipes as $recipe) {
            if ($recipe->items->isEmpty()) {
                // Resep tanpa rincian tetap ikut, supaya menu yang angkanya
                // masih dari Excel tidak hilang dari berkas dan bisa diisi
                // rinciannya di file yang sama.
                $rows->push($this->row($recipe));

                continue;
            }

            foreach ($recipe->items as $item) {
                $rows->push($this->row($recipe, $item));
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'resep_id',
            'nama_resep',
            'jenis',
            'kategori',
            'produk_id',
            'hasil_qty',
            'hasil_satuan',
            'ohc_persen',
            'profit_persen',
            'harga_target',
            'aktif',
            'urut',
            'kelompok',
            'nama_bahan',
            'jumlah',
            'satuan',
            'bahan_id',
            'sub_resep_id',
            'catatan_baris',
        ];
    }

    /** @return array<int, mixed> */
    protected function row(Recipe $recipe, ?RecipeItem $item = null): array
    {
        return [
            $recipe->id,
            $recipe->name,
            $recipe->jenis,
            $recipe->kategori,
            $recipe->product_id,
            (float) $recipe->yield_qty,
            $recipe->yield_unit,
            // Disimpan sebagai pecahan, ditulis sebagai persen -- begitulah
            // angkanya dibicarakan, dan begitu pula bentuknya di form.
            round((float) $recipe->ohc_pct * 100, 2),
            round((float) $recipe->profit_pct * 100, 2),
            $recipe->target_price !== null ? (float) $recipe->target_price : null,
            $recipe->is_active ? 'ya' : 'tidak',
            $item?->sort_order,
            $item?->section,
            $item?->raw_name,
            $item !== null ? (float) $item->qty : null,
            $item?->unit,
            $item?->inventory_item_id,
            $item?->ref_recipe_id,
            $item?->notes,
        ];
    }
}
