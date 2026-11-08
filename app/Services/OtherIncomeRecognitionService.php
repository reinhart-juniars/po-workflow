<?php

namespace App\Services;

use App\Models\OtherIncome;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Satu-satunya aturan pengakuan "pendapatan lain-lain" untuk laba rugi, dipakai neraca
 * (Laba Berjalan / Laba Ditahan) dan Laporan Laba Rugi supaya keduanya tidak bisa berbeda.
 *
 * - OtherIncome diakui pada income_date (ongkir closing tunai, ongkir pelunasan PO, pemasukan manual).
 * - Kembaran "Sales Actual" dari closing & kategori "Adjustment" tidak dihitung
 *   (penjualan sudah dihitung dari sales_actual_items; adjustment hanya koreksi kas).
 *
 * Kebijakan ongkir (sejak Agustus 2026): ongkir diakui saat DIBAYAR. Karena itu piutang PO di
 * neraca hanya memuat porsi barang (total - ongkir) — lihat BalanceSheetService::buildReceivableRows —
 * sehingga Kekayaan bulan ini = Kekayaan bulan lalu + Laba bulan ini.
 */
class OtherIncomeRecognitionService
{
    /**
     * @return Collection<string, float> nama kategori => jumlah
     */
    public function amountsByCategory(Carbon $dateFrom, Carbon $dateTo): Collection
    {
        return OtherIncome::query()
            ->with('category:id,name')
            ->whereDate('income_date', '>=', $dateFrom->toDateString())
            ->whereDate('income_date', '<=', $dateTo->toDateString())
            ->get(['income_category_id', 'source_type', 'source_id', 'amount', 'description'])
            ->reject(fn (OtherIncome $income) => $income->isGeneratedBySalesActual() || $this->isAdjustment($income))
            ->groupBy(fn (OtherIncome $income) => $income->category?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));
    }

    public function total(Carbon $dateFrom, Carbon $dateTo): float
    {
        return round((float) $this->amountsByCategory($dateFrom, $dateTo)->sum(), 2);
    }

    protected function isAdjustment(OtherIncome $income): bool
    {
        return strcasecmp(trim((string) ($income->category?->name ?? '')), 'Adjustment') === 0;
    }
}
