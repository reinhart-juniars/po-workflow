<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pasangan satuan yang masih menghalangi perhitungan HPP.
 *
 * Menjawab satu pertanyaan: aturan konversi mana yang paling berdampak kalau
 * diisi lebih dulu. Tanpa daftar ini, mengisi aturan satu per satu untuk 305
 * bahan adalah kerja buta -- padahal segelintir pasangan teratas menyumbang
 * ratusan baris resep sekaligus.
 *
 * Pengelompokannya dikerjakan basis data, penilaian bisa-tidaknya dikonversi
 * dikerjakan PHP: aturan konversi bisa berlaku dua arah dan bersambung dengan
 * registri satuan, jadi tidak bisa dinyatakan sebagai kondisi SQL.
 */
class MissingUnitConversionScanner
{
    public function __construct(
        protected IngredientUnitConverter $converter
    ) {}

    /**
     * Pasangan (bahan, satuan resep -> satuan harga) yang belum bisa dikonversi.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function scan(): Collection
    {
        // Aturan bisa saja baru ditambahkan pada permintaan yang sama; cache
        // aturan dibuang supaya daftar ini tidak menampilkan yang sudah beres.
        $this->converter->flush();

        return collect(DB::table('recipe_items as ri')
            ->join('inventory_items as ii', 'ii.id', '=', 'ri.inventory_item_id')
            ->whereNotNull('ri.inventory_item_id')
            ->selectRaw('ri.inventory_item_id as inventory_item_id')
            ->selectRaw('ii.name as item_name')
            ->selectRaw('ri.unit as from_unit')
            ->selectRaw('ii.unit as to_unit')
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COUNT(DISTINCT ri.recipe_id) as recipe_count')
            ->groupBy('ri.inventory_item_id', 'ii.name', 'ri.unit', 'ii.unit')
            ->get())
            ->reject(fn (object $row) => $this->converter->canConvert(
                $row->from_unit,
                $row->to_unit,
                (int) $row->inventory_item_id,
            ))
            ->map(fn (object $row) => [
                'id' => $row->inventory_item_id.'|'.$row->from_unit.'|'.$row->to_unit,
                'inventory_item_id' => (int) $row->inventory_item_id,
                'item_name' => $row->item_name,
                'from_unit' => (string) $row->from_unit,
                'to_unit' => (string) $row->to_unit,
                'line_count' => (int) $row->line_count,
                'recipe_count' => (int) $row->recipe_count,
            ])
            ->sortByDesc('line_count')
            ->values();
    }

    /**
     * Ringkasan sekali pandang untuk lencana navigasi dan judul halaman.
     *
     * @return array{pasangan: int, baris: int}
     */
    public function summary(): array
    {
        $rows = $this->scan();

        return [
            'pasangan' => $rows->count(),
            'baris' => (int) $rows->sum('line_count'),
        ];
    }
}
