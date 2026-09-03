<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InventoryPurchase;
use Carbon\Carbon;

trait ReportsDamagedInventoryLoss
{
    public const DAMAGED_INVENTORY_LOSS_LABEL = 'Kerugian Barang Rusak';

    /**
     * Nilai pembelian yang barangnya datang dalam kondisi tidak baik.
     *
     * Barang ini tidak menambah stok, sehingga nilainya sudah dikeluarkan dari
     * Bahan Baku oleh InventoryPurchase::addsToStock(). Uangnya tetap keluar,
     * jadi nilainya dilaporkan sebagai pos kerugian tersendiri. Karena yang
     * dikurangi dari Bahan Baku sama persis dengan yang ditambahkan ke
     * pengeluaran, total Laba tidak berubah -- yang berubah hanya komposisinya.
     */
    protected function damagedInventoryLossTotal(Carbon $dateFrom, Carbon $dateTo): float
    {
        return round((float) InventoryPurchase::query()
            ->damaged()
            ->whereDate('transaction_date', '>=', $dateFrom->toDateString())
            ->whereDate('transaction_date', '<=', $dateTo->toDateString())
            ->sum('total_value'), 2);
    }

    /**
     * Baris pengeluaran untuk kerugian barang rusak, atau null bila tidak ada.
     *
     * @return array{label: string, amount: float}|null
     */
    protected function damagedInventoryLossRow(Carbon $dateFrom, Carbon $dateTo): ?array
    {
        $amount = $this->damagedInventoryLossTotal($dateFrom, $dateTo);

        if (abs($amount) < 0.005) {
            return null;
        }

        return [
            'label' => self::DAMAGED_INVENTORY_LOSS_LABEL,
            'amount' => $amount,
        ];
    }
}
