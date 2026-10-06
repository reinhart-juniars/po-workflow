<?php

namespace App\Http\Controllers\Concerns;

use App\Services\InventoryShrinkageService;
use App\Services\LeftoverStockService;
use Carbon\Carbon;

trait ReportsHppDetails
{
    /**
     * Pos HPP di luar bahan baku per bucket.
     *
     * - Barang Sisa Awal/Akhir: persediaan retur (dinilai HPP menu). Awal
     *   menambah HPP, akhir mengurangi -- sama seperti Bahan Baku Lama dan
     *   Sisa Stok. Ini yang menggeser Laba: bahan baku untuk porsi yang belum
     *   terjual tidak dibebankan ke bulan ini.
     * - Barang Hilang/Temuan dan Barang Sisa Dibuang: rincian yang sudah
     *   termasuk di HPP (tidak menambah atau mengurangi Laba).
     *
     * @return array{barangSisaAwal: float, barangSisaAkhir: float, barangSisaDibuang: float, barangHilang: float, barangTemuan: float}
     */
    protected function hppDetails(Carbon $dateFrom, Carbon $dateTo): array
    {
        $leftovers = app(LeftoverStockService::class);
        $lossAndFound = app(InventoryShrinkageService::class)->lossAndFoundTotals($dateFrom, $dateTo);

        return [
            'barangSisaAwal' => $leftovers->valueAt($dateFrom->copy()->subDay()),
            'barangSisaAkhir' => $leftovers->valueAt($dateTo),
            'barangSisaDibuang' => $leftovers->wasteValueBetween($dateFrom, $dateTo),
            'barangHilang' => $lossAndFound['hilang'],
            'barangTemuan' => $lossAndFound['temuan'],
        ];
    }
}
