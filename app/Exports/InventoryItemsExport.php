<?php

namespace App\Exports;

use App\Models\InventoryItem;
use App\Services\InventoryStockAlertService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export item inventaris.
 *
 * Kolom id dan nama_item sengaja diletakkan di depan karena file hasil export
 * ini juga menjadi format import: id dipakai untuk memperbarui item yang sudah
 * ada, sedangkan baris tanpa id dianggap item baru.
 *
 * Kolom bahan (kelompok, kemasan, harga) ikut dibawa supaya harga bahan bisa
 * diperbarui borongan lewat Excel -- itulah cara klien mengurus 305 bahan.
 */
class InventoryItemsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(
        protected ?Collection $items = null
    ) {}

    public function collection(): Collection
    {
        return $this->items ?? InventoryItem::query()->with('parent')->orderBy('name')->get();
    }

    public function headings(): array
    {
        return [
            'id',
            'nama_item',
            'satuan',
            'kategori',
            'induk_id',
            'induk_nama',
            'kelompok_bahan',
            'isi_kemasan',
            'harga_kemasan',
            'harga_satuan',
            'nilai_stok_minimum',
            'aktif',
            'keterangan',
            'nilai_stok_berjalan',
        ];
    }

    /** @param  InventoryItem  $item */
    public function map($item): array
    {
        return [
            $item->id,
            $item->name,
            $item->unit,
            $item->category,
            $item->parent_id,
            $item->parent?->name,
            $item->ingredient_group,
            $item->pack_qty !== null ? (float) $item->pack_qty : null,
            $item->pack_price !== null ? (float) $item->pack_price : null,
            $item->unit_price !== null ? (float) $item->unit_price : null,
            $item->minimum_stock_value !== null ? (float) $item->minimum_stock_value : null,
            $item->is_active ? 'ya' : 'tidak',
            $item->description,
            app(InventoryStockAlertService::class)->currentStockValue($item->id)['value'],
        ];
    }
}
