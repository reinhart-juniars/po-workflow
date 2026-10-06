<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Alert stok minimum berbasis nilai stok berjalan.
 *
 * Sistem ini melacak stok dalam rupiah, bukan kuantitas, sehingga ambangnya
 * pun rupiah (InventoryItem::minimum_stock_value).
 *
 * Nilai stok berjalan dihitung dari opname terakhir ditambah pembelian yang
 * masuk setelahnya. Pemakaian di antara dua opname tidak diketahui -- dalam
 * model residual, pemakaian baru ketahuan saat opname berikutnya dilakukan.
 * Konsekuensinya nilai ini cenderung lebih tinggi dari stok sebenarnya,
 * sehingga alert menyala terlambat, bukan terlalu dini. Sifat ini hilang di
 * Phase 3 saat pemakaian dicatat sebagai mutasi keluar per SPK.
 */
class InventoryStockAlertService
{
    /**
     * Nilai stok berjalan sebuah item pada tanggal tertentu.
     *
     * @return array{value: float, basis: string, basis_date: Carbon|null}
     */
    public function currentStockValue(int $itemId, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? Carbon::parse($asOf) : Carbon::now();

        $opname = StockOpname::query()
            ->where('inventory_item_id', $itemId)
            ->whereDate('opname_date', '<=', $asOf->toDateString())
            ->orderByDesc('opname_date')
            ->orderByDesc('id')
            ->first(['id', 'opname_date', 'total_value']);

        $purchaseQuery = InventoryPurchase::query()
            ->addsToStock()
            ->where('inventory_item_id', $itemId)
            ->whereDate('transaction_date', '<=', $asOf->toDateString());

        if ($opname) {
            // Barang yang datang setelah opname terakhir belum terwakili di
            // angka opname, jadi ditambahkan.
            $purchases = (float) $purchaseQuery
                ->whereDate('transaction_date', '>', $opname->opname_date->toDateString())
                ->sum('total_value');

            return [
                'value' => round((float) $opname->total_value + $purchases, 2),
                'basis' => 'opname',
                'basis_date' => $opname->opname_date,
            ];
        }

        // Belum pernah diopname: pakai saldo awal ditambah seluruh pembelian.
        $opening = (float) InventoryOpening::query()
            ->where('inventory_item_id', $itemId)
            ->whereDate('balance_date', '<=', $asOf->toDateString())
            ->sum('total_value');

        return [
            'value' => round($opening + (float) $purchaseQuery->sum('total_value'), 2),
            'basis' => 'saldo_awal',
            'basis_date' => null,
        ];
    }

    /**
     * Item aktif yang nilai stoknya berada di bawah ambang minimum.
     *
     * Item tanpa ambang (minimum_stock_value NULL) tidak pernah ikut -- alert
     * adalah sesuatu yang dinyalakan secara sadar, bukan default.
     *
     * @return Collection<int, array{item: InventoryItem, stock_value: float, minimum: float, shortfall: float, basis: string}>
     */
    public function alerts(?CarbonInterface $asOf = null): Collection
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->whereNotNull('minimum_stock_value')
            ->orderBy('name')
            ->get()
            ->map(function (InventoryItem $item) use ($asOf) {
                $stock = $this->currentStockValue($item->id, $asOf);
                $minimum = (float) $item->minimum_stock_value;

                return [
                    'item' => $item,
                    'stock_value' => $stock['value'],
                    'minimum' => $minimum,
                    'shortfall' => round($minimum - $stock['value'], 2),
                    'basis' => $stock['basis'],
                ];
            })
            ->filter(fn (array $row) => $row['stock_value'] < $row['minimum'])
            ->values();
    }

    /** Jumlah item yang sedang di bawah ambang — dipakai badge navigasi. */
    public function alertCount(?CarbonInterface $asOf = null): int
    {
        return $this->alerts($asOf)->count();
    }
}
