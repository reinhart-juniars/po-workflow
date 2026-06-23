<?php

namespace App\Services;

use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\StockOpname;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class InventoryUsageService
{
    public function calculateForItem(int $itemId, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        return $this->buildItemReport($itemId, $dateFrom, $dateTo)['summary'];
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

        $purchaseEntries = InventoryPurchase::query()
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

        $summary = [
            'opening' => $opening,
            'purchases' => $purchases,
            'ending' => $ending,
            'usage' => $opening + $purchases - $ending,
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
                    $purchaseEntry->supplier_name ? 'Supplier: ' . $purchaseEntry->supplier_name : null,
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
            'notes' => null,
        ]);

        return $rows;
    }
}
