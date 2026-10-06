<?php

namespace App\Exports;

use App\Models\InventoryItemPriceHistory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Riwayat harga bahan -- semua bahan, atau satu bahan bila $inventoryItemId
 * diisi (padanan "Export Riwayat" Master Menu). Terbaru di atas.
 */
class PriceHistoryExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(
        protected ?int $inventoryItemId = null,
    ) {}

    public function headings(): array
    {
        return ['Tanggal', 'Bahan', 'Satuan', 'Perubahan', 'Harga Satuan Lama', 'Harga Satuan Baru', 'Harga Kemasan Lama', 'Harga Kemasan Baru', 'Sumber', 'Catatan'];
    }

    public function collection(): Collection
    {
        return InventoryItemPriceHistory::query()
            ->with('item:id,name,unit')
            ->when($this->inventoryItemId, fn ($query) => $query->where('inventory_item_id', $this->inventoryItemId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (InventoryItemPriceHistory $row) => [
                $row->created_at?->format('Y-m-d H:i'),
                $row->item?->name,
                $row->item?->unit,
                $row->actionLabel(),
                $row->old_unit_price !== null ? (float) $row->old_unit_price : null,
                $row->new_unit_price !== null ? (float) $row->new_unit_price : null,
                $row->old_pack_price !== null ? (float) $row->old_pack_price : null,
                $row->new_pack_price !== null ? (float) $row->new_pack_price : null,
                $row->source,
                $row->note,
            ]);
    }
}
