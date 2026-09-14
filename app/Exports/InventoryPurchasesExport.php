<?php

namespace App\Exports;

use App\Models\InventoryPurchase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export pembelian bahan baku untuk arsip/rekonsiliasi. Hanya export:
 * pembelian lahir dari modul Pengeluaran supaya jurnal kas/hutangnya ikut
 * terbentuk, jadi tidak ada jalur import di sini.
 */
class InventoryPurchasesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function collection(): Collection
    {
        return InventoryPurchase::query()
            ->with(['item:id,name', 'requisition:id,number'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    public function headings(): array
    {
        return ['id', 'tanggal', 'item_id', 'nama_item', 'supplier', 'qty', 'harga_satuan', 'total', 'pembayaran', 'kondisi', 'catatan_kondisi', 'form_kebutuhan', 'catatan'];
    }

    /** @param  InventoryPurchase  $row */
    public function map($row): array
    {
        return [
            $row->id,
            $row->transaction_date?->format('Y-m-d'),
            $row->inventory_item_id,
            $row->item?->name,
            $row->supplier_name,
            (float) $row->qty,
            (float) $row->unit_cost,
            (float) $row->total_value,
            $row->payment_type,
            $row->conditionLabel(),
            $row->condition_notes,
            $row->requisition?->number,
            $row->notes,
        ];
    }
}
