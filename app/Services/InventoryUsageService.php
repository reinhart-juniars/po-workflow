<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use App\Support\Settings\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class InventoryUsageService
{
    public function __construct(
        protected InventoryLedgerService $ledger,
    ) {}

    public function calculateForItem(int $itemId, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        return $this->buildItemReport($itemId, $dateFrom, $dateTo)['summary'];
    }

    /**
     * Ringkasan pemakaian banyak bahan sekaligus, dengan angka yang sama persis
     * dengan calculateForItem() per bahan.
     *
     * Laporan Laba Rugi, Final, Neraca, dan Analisa HPP menghitung ~300 bahan
     * per periode; lewat calculateForItem() itu 7 query per bahan (±2.100 per
     * periode, Analisa HPP setahun >20.000 query dan melewati batas 30 detik).
     * Di sini tiap sumber diambil sekali untuk semua bahan dengan kondisi
     * where yang identik, lalu dikelompokkan per bahan di PHP dengan urutan
     * yang sama -- termasuk urutan penjumlahan pembelian, supaya hasil float
     * tidak bergeser.
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, array<string, mixed>> ringkasan per id bahan
     */
    public function summariesForItems(iterable $itemIds, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $priorWindowEnd = $dateFrom->copy()->subDay();
        $priorWindowStart = $dateFrom->copy()->subMonthNoOverflow();

        $priorOpnames = $this->latestOpnameValues($ids, $priorWindowStart, $priorWindowEnd);
        $endingOpnames = $this->latestOpnameValues($ids, $dateFrom, $dateTo);

        // Saldo awal di window bulan sebelumnya s/d dateFrom (sama dengan
        // fallback 'baseline' di buildItemReport).
        $baselines = InventoryOpening::query()
            ->whereIn('inventory_item_id', $ids)
            ->whereDate('balance_date', '>=', $priorWindowStart->toDateString())
            ->whereDate('balance_date', '<=', $dateFrom->toDateString())
            ->selectRaw('inventory_item_id, SUM(total_value) as total')
            ->groupBy('inventory_item_id')
            ->pluck('total', 'inventory_item_id');

        // Dijumlah di PHP dalam urutan transaction_date, id -- sama dengan
        // $purchaseEntries->sum() di buildItemReport.
        $purchases = [];
        InventoryPurchase::query()
            ->addsToStock()
            ->whereIn('inventory_item_id', $ids)
            ->whereBetween('transaction_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['inventory_item_id', 'total_value'])
            ->each(function (InventoryPurchase $purchase) use (&$purchases) {
                $purchases[$purchase->inventory_item_id] = ($purchases[$purchase->inventory_item_id] ?? 0) + $purchase->total_value;
            });

        $recipeUsage = $this->ledger->valuesForBuckets($ids->all(), InventoryMovement::TYPE_USAGE, $dateFrom->toDateString(), $dateTo->toDateString());
        $recipeAdjustment = $this->ledger->valuesForBuckets($ids->all(), InventoryMovement::TYPE_ADJUSTMENT, $dateFrom->toDateString(), $dateTo->toDateString());
        $usageSource = app(Settings::class)->get('hpp.usage_source');

        $summaries = [];

        foreach ($ids as $itemId) {
            [$opening, $openingSource] = isset($priorOpnames[$itemId])
                ? [$priorOpnames[$itemId], 'opname']
                : [0.0, 'zero'];

            if ($openingSource === 'zero') {
                $baseline = (float) ($baselines[$itemId] ?? 0);

                if (abs($baseline) >= 0.005) {
                    $opening = $baseline;
                    $openingSource = 'baseline';
                }
            }

            [$ending, $endingSource] = isset($endingOpnames[$itemId])
                ? [$endingOpnames[$itemId], 'opname']
                : [0.0, 'zero'];

            $itemPurchases = (float) ($purchases[$itemId] ?? 0);
            $itemRecipeUsage = round(-($recipeUsage[$itemId] ?? 0.0), 2);
            $itemRecipeAdjustment = round(-($recipeAdjustment[$itemId] ?? 0.0), 2);
            $residualUsage = round($opening + $itemPurchases - $ending, 2);

            $summaries[$itemId] = [
                'opening' => $opening,
                'purchases' => $itemPurchases,
                'ending' => $ending,
                'usage' => $usageSource === 'resep' ? round($itemRecipeUsage + $itemRecipeAdjustment, 2) : $residualUsage,
                'usage_source' => $usageSource,
                'usage_residual' => $residualUsage,
                'usage_recipe' => $itemRecipeUsage,
                'adjustment_recipe' => $itemRecipeAdjustment,
                'opening_source' => $openingSource,
                'ending_source' => $endingSource,
            ];
        }

        return $summaries;
    }

    /**
     * Nilai opname terakhir (opname_date, lalu id terbesar) per bahan di dalam
     * window -- versi banyak bahan dari stockValueForWindow().
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, float>
     */
    protected function latestOpnameValues(Collection $ids, CarbonInterface $windowStart, CarbonInterface $windowEnd): array
    {
        $values = [];

        StockOpname::query()
            ->whereIn('inventory_item_id', $ids)
            ->whereDate('opname_date', '>=', $windowStart->toDateString())
            ->whereDate('opname_date', '<=', $windowEnd->toDateString())
            ->orderByDesc('opname_date')
            ->orderByDesc('id')
            ->get(['inventory_item_id', 'total_value'])
            ->each(function (StockOpname $opname) use (&$values) {
                // Baris pertama per bahan = yang terbaru (urutan query).
                $values[$opname->inventory_item_id] ??= (float) $opname->total_value;
            });

        return $values;
    }

    /**
     * Resolve stock value at the end of a given window — STRICT.
     *
     * Only counts opnames whose opname_date falls inside [windowStart, windowEnd].
     * Anything outside that window contributes nothing. This keeps opening of
     * period P+1 equal to ending of period P, since both resolve against the
     * same window.
     *
     * Returns [value, opname (if used), source].
     */
    protected function stockValueForWindow(
        int $itemId,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd
    ): array {
        $opname = StockOpname::query()
            ->where('inventory_item_id', $itemId)
            ->whereDate('opname_date', '>=', $windowStart->toDateString())
            ->whereDate('opname_date', '<=', $windowEnd->toDateString())
            ->orderByDesc('opname_date')
            ->orderByDesc('id')
            ->first([
                'id',
                'opname_date',
                'qty',
                'unit_cost',
                'total_value',
                'notes',
            ]);

        if ($opname) {
            return [(float) $opname->total_value, $opname, 'opname'];
        }

        return [0.0, null, 'zero'];
    }

    public function buildItemReport(int $itemId, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        $openingEntries = InventoryOpening::query()
            ->where('inventory_item_id', $itemId)
            ->whereDate('balance_date', '<=', $dateFrom->toDateString())
            ->orderBy('balance_date')
            ->orderBy('id')
            ->get([
                'id',
                'balance_date',
                'qty',
                'unit_cost',
                'total_value',
                'notes',
            ]);

        // Prior window = the previous calendar month relative to dateFrom.
        // For a monthly filter (e.g. May 1 - May 31), this yields [Apr 1, Apr 30]
        // which is exactly the ending window of the previous month — so
        // opening of period P matches ending of period P-1 by construction.
        //
        // STRICT one-step carry: hanya opname yang dilakukan di window prior bulan
        // yang dihitung sebagai "Bahan Baku Lama". Tidak ada fallback ke
        // InventoryOpening baseline atau ke opname lebih lama dari prior window —
        // jika tidak ada opname di prior window, opening = 0 (match ending periode
        // sebelumnya yang juga 0).
        $priorWindowEnd = $dateFrom->copy()->subDay();
        $priorWindowStart = $dateFrom->copy()->subMonthNoOverflow();

        [$opening, $priorOpname, $openingSource] = $this->stockValueForWindow(
            $itemId,
            $priorWindowStart,
            $priorWindowEnd
        );

        // Periode pertama (software start): belum ada opname penutup bulan sebelumnya,
        // jadi stok lama diambil dari saldo awal (InventoryOpening) yang tanggalnya
        // jatuh di window bulan sebelumnya. Scope-nya sengaja dibatasi ke prior window
        // supaya tidak mengganggu carry strict periode berikutnya (opening P = ending P-1).
        if ($openingSource === 'zero') {
            $baseline = (float) InventoryOpening::query()
                ->where('inventory_item_id', $itemId)
                ->whereDate('balance_date', '>=', $priorWindowStart->toDateString())
                ->whereDate('balance_date', '<=', $dateFrom->toDateString())
                ->sum('total_value');

            if (abs($baseline) >= 0.005) {
                $opening = $baseline;
                $openingSource = 'baseline';
            }
        }

        // Barang yang datang rusak tidak pernah masuk stok, jadi tidak boleh
        // ikut menambah "Bahan Baku Baru" maupun mengurangi pemakaian.
        $purchaseEntries = InventoryPurchase::query()
            ->addsToStock()
            ->where('inventory_item_id', $itemId)
            ->whereBetween('transaction_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get([
                'id',
                'transaction_date',
                'qty',
                'unit_cost',
                'total_value',
                'payment_type',
                'supplier_name',
                'notes',
            ]);

        $purchases = (float) $purchaseEntries->sum('total_value');

        [$ending, $endingOpname, $endingSource] = $this->stockValueForWindow(
            $itemId,
            $dateFrom,
            $dateTo
        );

        // Pemakaian menurut resep x produksi (ledger Phase 3) selalu ikut
        // dihitung sebagai pembanding. Mana yang menjadi 'usage' -- angka yang
        // dibaca Laba Rugi, Neraca, dan laporan pemakaian -- ditentukan
        // pengaturan hpp.usage_source: residual sampai masa paralel disetujui
        // Owner, sesudah itu ledger resep (pemakaian + penyesuaian sisa fisik).
        $recipeUsage = round(-$this->ledger->valueForBucket(
            $itemId,
            InventoryMovement::TYPE_USAGE,
            $dateFrom->toDateString(),
            $dateTo->toDateString(),
        ), 2);
        $recipeAdjustment = round(-$this->ledger->valueForBucket(
            $itemId,
            InventoryMovement::TYPE_ADJUSTMENT,
            $dateFrom->toDateString(),
            $dateTo->toDateString(),
        ), 2);

        $residualUsage = round($opening + $purchases - $ending, 2);
        $usageSource = app(Settings::class)->get('hpp.usage_source');

        $summary = [
            'opening' => $opening,
            'purchases' => $purchases,
            'ending' => $ending,
            'usage' => $usageSource === 'resep' ? round($recipeUsage + $recipeAdjustment, 2) : $residualUsage,
            'usage_source' => $usageSource,
            'usage_residual' => $residualUsage,
            'usage_recipe' => $recipeUsage,
            'adjustment_recipe' => $recipeAdjustment,
            'opening_source' => $openingSource,
            'ending_source' => $endingSource,
        ];

        return [
            'summary' => $summary,
            'detail_rows' => $this->buildDetailRows(
                $openingEntries,
                $purchaseEntries,
                $endingOpname,
                $priorOpname,
                $summary
            ),
        ];
    }

    protected function buildDetailRows(
        Collection $openingEntries,
        Collection $purchaseEntries,
        ?StockOpname $endingOpname,
        ?StockOpname $priorOpname,
        array $summary
    ): Collection {
        $rows = collect();
        $openingSource = $summary['opening_source'] ?? 'zero';

        if ($openingSource === 'opname' && $priorOpname) {
            $rows->push([
                'kind' => 'entry',
                'date' => $priorOpname->opname_date,
                'date_text' => optional($priorOpname->opname_date)->format('d-m-Y'),
                'type' => 'Bahan Baku Lama',
                'qty' => (float) $priorOpname->qty,
                'unit_cost' => (float) $priorOpname->unit_cost,
                'value' => (float) $priorOpname->total_value,
                'notes' => 'Sisa stok dari opname periode sebelumnya.',
            ]);
        }

        $rows->push([
            'kind' => 'subtotal',
            'label' => 'Total Bahan Baku Lama',
            'value' => (float) ($summary['opening'] ?? 0),
            'notes' => $openingSource === 'opname'
                ? null
                : 'Belum ada opname di bulan sebelumnya; bahan baku lama dianggap 0.',
        ]);

        foreach ($purchaseEntries as $purchaseEntry) {
            $rows->push([
                'kind' => 'entry',
                'date' => $purchaseEntry->transaction_date,
                'date_text' => optional($purchaseEntry->transaction_date)->format('d-m-Y'),
                'type' => 'Pembelian',
                'qty' => (float) $purchaseEntry->qty,
                'unit_cost' => (float) $purchaseEntry->unit_cost,
                'value' => (float) $purchaseEntry->total_value,
                'notes' => collect([
                    $purchaseEntry->supplier_name ? 'Supplier: '.$purchaseEntry->supplier_name : null,
                    $purchaseEntry->payment_type === 'payable' ? 'Kredit' : 'Tunai',
                    $purchaseEntry->notes,
                ])->filter()->implode(' | '),
            ]);
        }

        $rows->push([
            'kind' => 'subtotal',
            'label' => 'Total Pembelian',
            'value' => (float) ($summary['purchases'] ?? 0),
            'notes' => $purchaseEntries->isEmpty()
                ? 'Tidak ada pembelian pada periode ini.'
                : null,
        ]);

        if ($endingOpname) {
            $rows->push([
                'kind' => 'entry',
                'date' => $endingOpname->opname_date,
                'date_text' => optional($endingOpname->opname_date)->format('d-m-Y'),
                'type' => 'Stock Opname',
                'qty' => (float) $endingOpname->qty,
                'unit_cost' => (float) $endingOpname->unit_cost,
                'value' => (float) $endingOpname->total_value,
                'notes' => $endingOpname->notes ?: 'Dipakai sebagai sisa stok periode.',
            ]);
        }

        $endingSource = $summary['ending_source'] ?? null;
        $endingNote = match ($endingSource) {
            'opname' => 'Mengacu ke stock opname periode ini.',
            'baseline' => 'Belum ada opname; pakai saldo awal sebagai sisa stok.',
            'zero' => 'Belum ada opname pada periode ini; sisa stok dianggap 0 supaya konsisten dengan opening periode berikutnya.',
            default => 'Belum ada stock opname pada periode ini.',
        };

        $rows->push([
            'kind' => 'subtotal',
            'label' => 'Sisa Stok',
            'value' => (float) ($summary['ending'] ?? 0),
            'notes' => $endingNote,
        ]);

        $rows->push([
            'kind' => 'result',
            'label' => 'Total Pemakaian',
            'value' => (float) ($summary['usage'] ?? 0),
            'notes' => ($summary['usage_source'] ?? 'residual') === 'resep'
                ? 'Dari resep x produksi (kartu stok: pemakaian + penyesuaian sisa fisik); residual opname Rp '.number_format((float) ($summary['usage_residual'] ?? 0), 2, ',', '.').' hanya pembanding.'
                : null,
        ]);

        return $rows;
    }
}
