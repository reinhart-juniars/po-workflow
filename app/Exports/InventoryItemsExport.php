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
 */
class InventoryItemsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(
        protected ?Collection $items = null
    ) {}

    public function collection(): Collection
    {
        return $this->items ?? InventoryItem::query()->orderBy('name')->get();
    }

    public function headings(): array
    {
        return [
            'id',
            'nama_item',
            'satuan',
            'kategori',
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
            $item->minimum_stock_value !== null ? (float) $item->minimum_stock_value : null,
            $item->is_active ? 'ya' : 'tidak',
            $item->description,
            app(InventoryStockAlertService::class)->currentStockValue($item->id)['value'],
        ];
    }
}
