<?php

namespace App\Services;

use App\Models\SalesActual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    public function buildReportData(Request $request): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $viewMode = (string) $request->input('view_mode', 'summary');
        $segmentScope = (string) $request->input('segment_scope', 'all');

        if (! in_array($viewMode, ['summary', 'full'], true)) {
            $viewMode = 'summary';
        }

        if (! in_array($segmentScope, ['all', 'lapak', 'non_lapak'], true)) {
            $segmentScope = 'all';
        }

        $allActuals = SalesActual::query()
            ->with('customer:id,name,is_lapak')
            ->where('status', 'submitted')
            ->whereBetween('sales_date', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->orderBy('sales_date')
            ->orderBy('customer_id')
            ->orderBy('id')
            ->get();

        $actuals = $allActuals
            ->when($segmentScope === 'lapak', fn ($collection) => $collection->filter(fn (SalesActual $sa) => (bool) ($sa->customer?->is_lapak ?? false)))
            ->when($segmentScope === 'non_lapak', fn ($collection) => $collection->filter(fn (SalesActual $sa) => ! (bool) ($sa->customer?->is_lapak ?? false)))
            ->values();

        $lines = $this->loadReportLines($allActuals);

        $sumLines = function (SalesActual $sa, string $field) use ($lines): float {
            $sum = 0;

            foreach ($lines[$sa->id] as $line) {
                $sum += $line[$field];
            }

            return (float) $sum;
        };
        $salesActualTotal = fn (SalesActual $sa) => round($sumLines($sa, 'subtotal'), 2);
        $salesActualQty = fn (SalesActual $sa) => round($sumLines($sa, 'qty'), 2);
        $allAmount = round((float) $allActuals->sum($salesActualTotal), 2);

        $segmentCards = collect([
            [
                'key' => 'all',
                'label' => 'Semua',
                'actuals' => $allActuals,
            ],
            [
                'key' => 'lapak',
                'label' => 'Lapak',
                'actuals' => $allActuals->filter(fn (SalesActual $sa) => (bool) ($sa->customer?->is_lapak ?? false))->values(),
            ],
            [
                'key' => 'non_lapak',
                'label' => 'Retail',
                'actuals' => $allActuals->filter(fn (SalesActual $sa) => ! (bool) ($sa->customer?->is_lapak ?? false))->values(),
            ],
        ])->map(function (array $segment) use ($allAmount, $salesActualTotal, $salesActualQty) {
            $totalAmount = round((float) $segment['actuals']->sum($salesActualTotal), 2);
            $totalQty = (int) $segment['actuals']->sum($salesActualQty);
            $totalOrders = (int) $segment['actuals']->count();

            return [
                'key' => $segment['key'],
                'label' => $segment['label'],
                'total_amount' => $totalAmount,
                'total_qty' => $totalQty,
                'total_orders' => $totalOrders,
                'share_percent' => $allAmount > 0 ? round(($totalAmount / $allAmount) * 100, 2) : null,
            ];
        })->values()->all();

        $itemColumns = $actuals
            ->flatMap(fn (SalesActual $sa) => array_column($lines[$sa->id], 'label'))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->values()
            ->all();

        $priceColumns = $actuals
            ->flatMap(fn (SalesActual $sa) => array_column($lines[$sa->id], 'price'))
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($price) => [
                'key' => $this->salesReportPriceKey((float) $price),
                'amount' => (float) $price,
                'label' => $this->salesReportPriceLabel((float) $price),
            ])
            ->all();

        // Peta per baris dibuat JARANG (hanya menu/harga yang ada di baris itu).
        // Versi padat (semua kolom x semua baris) membuat laporan setahun
        // berjalan puluhan detik: tiap penjumlahan menyapu baris x ratusan kolom.
        // Semua pembaca memakai `?? 0`, dan urutan menu per baris dijaga sama
        // dengan urutan kolom supaya tampilan tumpukan item tidak berubah.
        $itemRank = array_flip($itemColumns);

        $rowData = $actuals->map(function (SalesActual $sa) use ($itemRank, $lines, $sumLines) {
            $itemQtyMap = [];
            $priceAmountMap = [];
            $priceQtyMap = [];
            $hasThreeS = false;

            foreach ($lines[$sa->id] as $line) {
                $itemLabel = $line['label'];
                $priceKey = $this->salesReportPriceKey($line['price']);
                $qtyActual = $line['qty'];
                $subtotalActual = $line['subtotal'];

                $itemQtyMap[$itemLabel] = ($itemQtyMap[$itemLabel] ?? 0) + $qtyActual;
                $priceAmountMap[$priceKey] = ($priceAmountMap[$priceKey] ?? 0) + $subtotalActual;
                $priceQtyMap[$priceKey] = ($priceQtyMap[$priceKey] ?? 0) + $qtyActual;

                if ($line['is_3s']) {
                    $hasThreeS = true;
                }
            }

            uksort($itemQtyMap, fn ($a, $b) => ($itemRank[$a] ?? PHP_INT_MAX) <=> ($itemRank[$b] ?? PHP_INT_MAX));

            $purchaseOrders = collect(array_column($lines[$sa->id], 'purchase_order'))
                ->filter()
                ->unique('id')
                ->values();

            $poNumbers = $purchaseOrders->pluck('po_number')->filter()->unique()->values();
            $recipientNames = $purchaseOrders->pluck('recipient_name')->filter()->unique()->values();
            $shippingCost = round((float) $purchaseOrders->sum('shipping_cost'), 2);

            $isLapak = (bool) ($sa->customer?->is_lapak ?? false);
            $sectionKey = $isLapak ? 'lapak' : ($hasThreeS ? 'tiga_s' : 'wwp');

            $customerLabel = $sa->customer?->name
                ?? $recipientNames->first()
                ?? ('SA '.$sa->id);

            $poLine = $poNumbers->isNotEmpty() ? $poNumbers->implode(', ') : null;
            $recipientLine = $recipientNames->isNotEmpty() ? 'Penerima: '.$recipientNames->implode(', ') : null;

            return [
                'date_key' => $sa->sales_date?->toDateString(),
                'date_label' => $sa->sales_date?->format('d M Y'),
                'customer_label' => $customerLabel,
                'order_meta_lines' => collect([$poLine, $recipientLine])->filter()->values()->all(),
                'order_meta' => trim(collect([$poLine, $recipientLine])->filter()->implode("\n")),
                'is_lapak' => $isLapak,
                'has_three_s' => $hasThreeS,
                'section_key' => $sectionKey,
                'item_qty_map' => $itemQtyMap,
                'price_amount_map' => $priceAmountMap,
                'price_qty_map' => $priceQtyMap,
                'total_qty' => (int) $sumLines($sa, 'qty'),
                'shipping_cost' => $shippingCost,
                'total_amount' => round($sumLines($sa, 'subtotal'), 2),
            ];
        });

        $grandTotalSales = round((float) $rowData->sum('total_amount'), 2);
        $segmentKeys = [
            'lapak' => 'Lapak',
            'non_lapak' => 'Retail',
        ];

        $salesGroups = $rowData
            ->groupBy('date_key')
            ->map(function ($rows, $dateKey) use ($itemColumns, $priceColumns, $grandTotalSales, $segmentKeys) {
                [$itemTotals, $priceTotals, $priceQtyTotals] = $this->sumRowMaps($rows, $itemColumns, $priceColumns);

                $subtotalAmount = round((float) $rows->sum('total_amount'), 2);
                $segmentBreakdown = collect($segmentKeys)->map(function (string $label, string $key) use (
                    $rows,
                    $itemColumns,
                    $priceColumns,
                    $grandTotalSales,
                    $subtotalAmount
                ) {
                    $segmentRows = $rows
                        ->filter(fn ($row) => $key === 'lapak' ? (bool) ($row['is_lapak'] ?? false) : ! (bool) ($row['is_lapak'] ?? false))
                        ->values();

                    [$segmentItemTotals, $segmentPriceTotals] = $this->sumRowMaps($segmentRows, $itemColumns, $priceColumns);

                    $segmentSubtotal = round((float) $segmentRows->sum('total_amount'), 2);

                    return [
                        'key' => $key,
                        'label' => $label,
                        'rows_count' => (int) $segmentRows->count(),
                        'item_totals' => $segmentItemTotals,
                        'price_totals' => collect($segmentPriceTotals)->map(fn ($amount) => round((float) $amount, 2))->all(),
                        'total_qty' => (int) $segmentRows->sum('total_qty'),
                        'shipping_total' => round((float) $segmentRows->sum('shipping_cost'), 2),
                        'subtotal_amount' => $segmentSubtotal,
                        'period_share_percent' => $grandTotalSales > 0 ? round(($segmentSubtotal / $grandTotalSales) * 100, 2) : null,
                        'group_share_percent' => $subtotalAmount > 0 ? round(($segmentSubtotal / $subtotalAmount) * 100, 2) : null,
                    ];
                })->values()->all();

                return [
                    'date_key' => $dateKey,
                    'date_label' => $rows->first()['date_label'] ?? $dateKey,
                    'rows' => $rows->values()->all(),
                    'item_totals' => $itemTotals,
                    'price_totals' => collect($priceTotals)->map(fn ($amount) => round((float) $amount, 2))->all(),
                    'price_qty_totals' => $priceQtyTotals,
                    'total_qty' => (int) $rows->sum('total_qty'),
                    'shipping_total' => round((float) $rows->sum('shipping_cost'), 2),
                    'subtotal_amount' => $subtotalAmount,
                    'percentage' => $grandTotalSales > 0 ? round(($subtotalAmount / $grandTotalSales) * 100, 2) : null,
                    'segment_breakdown' => $segmentBreakdown,
                ];
            })
            ->values()
            ->all();

        [$grandItemTotals, $grandPriceTotals, $grandPriceQtyTotals] = $this->sumRowMaps($rowData, $itemColumns, $priceColumns);

        $grandSegmentBreakdown = collect($segmentKeys)->map(function (string $label, string $key) use (
            $rowData,
            $itemColumns,
            $priceColumns,
            $grandTotalSales
        ) {
            $segmentRows = $rowData
                ->filter(fn ($row) => $key === 'lapak' ? (bool) ($row['is_lapak'] ?? false) : ! (bool) ($row['is_lapak'] ?? false))
                ->values();

            [$segmentItemTotals, $segmentPriceTotals] = $this->sumRowMaps($segmentRows, $itemColumns, $priceColumns);

            $segmentSubtotal = round((float) $segmentRows->sum('total_amount'), 2);

            return [
                'key' => $key,
                'label' => $label,
                'rows_count' => (int) $segmentRows->count(),
                'item_totals' => $segmentItemTotals,
                'price_totals' => collect($segmentPriceTotals)->map(fn ($amount) => round((float) $amount, 2))->all(),
                'total_qty' => (int) $segmentRows->sum('total_qty'),
                'shipping_total' => round((float) $segmentRows->sum('shipping_cost'), 2),
                'subtotal_amount' => $segmentSubtotal,
                'period_share_percent' => $grandTotalSales > 0 ? round(($segmentSubtotal / $grandTotalSales) * 100, 2) : null,
                'group_share_percent' => null,
            ];
        })->values()->all();

        $salesGrandTotals = [
            'item_totals' => $grandItemTotals,
            'price_totals' => collect($grandPriceTotals)->map(fn ($amount) => round((float) $amount, 2))->all(),
            'price_qty_totals' => $grandPriceQtyTotals,
            'total_qty' => (int) $rowData->sum('total_qty'),
            'shipping_total' => round((float) $rowData->sum('shipping_cost'), 2),
            'grand_total' => $grandTotalSales,
            'total_orders' => $actuals->count(),
            'segment_breakdown' => $grandSegmentBreakdown,
        ];

        $salesSections = $this->buildSections($rowData, $priceColumns, $grandTotalSales);

        return [
            'viewMode' => $viewMode,
            'segmentScope' => $segmentScope,
            'segmentCards' => $segmentCards,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'itemColumns' => $itemColumns,
            'priceColumns' => $priceColumns,
            'salesGroups' => $salesGroups,
            'salesGrandTotals' => $salesGrandTotals,
            'salesSections' => $salesSections,
        ];
    }

    /**
     * Build per-section worksheets (Lapak / Warung Wuenak Poll / 3S) used by
     * the accounting "Lihat Lengkap" full-size layout. Each PO is bucketed
     * once: Lapak when customer is_lapak; otherwise 3S if any item is_3s,
     * else Warung Wuenak Poll.
     */
    private function buildSections($rowData, array $priceColumns, float $grandTotalSales): array
    {
        $sectionDefs = [
            ['key' => 'lapak', 'label' => 'Lapak', 'description' => 'Penjualan dengan customer kategori lapak'],
            ['key' => 'wwp', 'label' => 'Warung Wuenak Poll', 'description' => 'Customer non-lapak dan menu non-3S'],
            ['key' => 'tiga_s', 'label' => '3S', 'description' => 'Customer non-lapak dan menu 3S'],
        ];

        return collect($sectionDefs)->map(function (array $def) use ($rowData, $priceColumns, $grandTotalSales) {
            $sectionRows = $rowData->where('section_key', $def['key'])->values();
            $sectionTotal = round((float) $sectionRows->sum('total_amount'), 2);
            $sectionShipping = round((float) $sectionRows->sum('shipping_cost'), 2);
            $sectionQty = (int) $sectionRows->sum('total_qty');

            [, $sectionPriceAmount, $sectionPriceQty] = $this->sumRowMaps($sectionRows, [], $priceColumns);

            $groups = $sectionRows
                ->groupBy('date_key')
                ->map(function ($rows, $dateKey) use ($priceColumns, $grandTotalSales, $sectionTotal) {
                    [, $priceAmount, $priceQty] = $this->sumRowMaps($rows, [], $priceColumns);

                    $subtotal = round((float) $rows->sum('total_amount'), 2);

                    return [
                        'date_key' => $dateKey,
                        'date_label' => $rows->first()['date_label'] ?? $dateKey,
                        'rows' => $rows->values()->all(),
                        'price_qty_totals' => $priceQty,
                        'price_totals' => collect($priceAmount)->map(fn ($amount) => round((float) $amount, 2))->all(),
                        'total_qty' => (int) $rows->sum('total_qty'),
                        'shipping_total' => round((float) $rows->sum('shipping_cost'), 2),
                        'subtotal_amount' => $subtotal,
                        'period_share_percent' => $grandTotalSales > 0 ? round(($subtotal / $grandTotalSales) * 100, 2) : null,
                        'section_share_percent' => $sectionTotal > 0 ? round(($subtotal / $sectionTotal) * 100, 2) : null,
                    ];
                })
                ->values()
                ->all();

            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'description' => $def['description'],
                'groups' => $groups,
                'rows_count' => (int) $sectionRows->count(),
                'price_qty_totals' => $sectionPriceQty,
                'price_totals' => collect($sectionPriceAmount)->map(fn ($amount) => round((float) $amount, 2))->all(),
                'total_qty' => $sectionQty,
                'shipping_total' => $sectionShipping,
                'subtotal_amount' => $sectionTotal,
                'period_share_percent' => $grandTotalSales > 0 ? round(($sectionTotal / $grandTotalSales) * 100, 2) : null,
            ];
        })->values()->all();
    }

    /**
     * Jumlahkan peta jarang baris laporan ke peta padat berurutan kolom.
     *
     * Hanya entri yang ada di tiap baris yang disapu (O(item per baris)),
     * bukan baris x seluruh kolom. Pembulatannya sama dengan versi lama:
     * qty dijumlah sebagai int per baris, nominal sebagai float.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $itemColumns
     * @param  list<array{key: string}>  $priceColumns
     * @return array{0: array<string, int>, 1: array<string, float>, 2: array<string, int>}
     */
    private function sumRowMaps(iterable $rows, array $itemColumns, array $priceColumns): array
    {
        $priceKeys = array_column($priceColumns, 'key');
        $itemTotals = array_fill_keys($itemColumns, 0);
        $priceTotals = array_fill_keys($priceKeys, 0.0);
        $priceQtyTotals = array_fill_keys($priceKeys, 0);

        foreach ($rows as $row) {
            if ($itemColumns !== []) {
                foreach ($row['item_qty_map'] as $label => $qty) {
                    if (isset($itemTotals[$label])) {
                        $itemTotals[$label] += (int) $qty;
                    }
                }
            }

            foreach ($row['price_amount_map'] as $key => $amount) {
                if (isset($priceTotals[$key])) {
                    $priceTotals[$key] += (float) $amount;
                }
            }

            foreach ($row['price_qty_map'] as $key => $qty) {
                if (isset($priceQtyTotals[$key])) {
                    $priceQtyTotals[$key] += (int) $qty;
                }
            }
        }

        return [$itemTotals, $priceTotals, $priceQtyTotals];
    }

    public function pdfPaperSize(array $reportData, bool $useSectionLayout = false): string|array
    {
        if (($reportData['viewMode'] ?? 'summary') !== 'full') {
            return 'a4';
        }

        if ($useSectionLayout) {
            return 'a4';
        }

        $itemColumnCount = count($reportData['itemColumns'] ?? []);
        $priceColumnCount = count($reportData['priceColumns'] ?? []);

        $width = max(
            1190,
            390 + ($itemColumnCount * 72) + ($priceColumnCount * 92)
        );

        return [0, 0, $width, 842];
    }

    /**
     * Baris item per Sales Actual sebagai array biasa: label, harga, qty,
     * subtotal, menu 3S, dan PO asal (mengikuti rantai Barang Sisa/carry
     * forward sampai ketemu PO).
     *
     * Sengaja tanpa model Eloquent: laporan setahun menyentuh puluhan ribu item,
     * dan menghidrasi item + PO item + PO sebagai model (serta cast decimal di
     * tiap akses) memakan detik. Bentuk query item sama dengan eager load
     * `items` (satu WHERE sales_actual_id IN ...), jadi urutan item per Sales
     * Actual -- yang menentukan urutan kolom bila jumlahnya seri -- tidak berubah.
     *
     * @param  Collection<int, SalesActual>  $actuals
     * @return array<int, list<array{label: string, price: float, qty: float, subtotal: float, is_3s: bool, purchase_order: ?object}>>
     */
    private function loadReportLines(Collection $actuals): array
    {
        $columns = ['id', 'sales_actual_id', 'product_id', 'purchase_order_item_id', 'purchase_order_id', 'source_sales_actual_item_id', 'item_name', 'unit_price', 'qty_actual', 'subtotal_actual'];
        $lines = $actuals->mapWithKeys(fn (SalesActual $sa) => [$sa->id => []])->all();

        if ($lines === []) {
            return $lines;
        }

        $items = DB::table('sales_actual_items')
            ->whereIntegerInRaw('sales_actual_id', array_keys($lines))
            ->get($columns);

        // Rantai sumber (Barang Sisa / carry forward) dimuat bertingkat sampai habis.
        $byId = $items->keyBy('id')->all();
        $pending = $items->pluck('source_sales_actual_item_id')->filter()->unique()->reject(fn ($id) => isset($byId[$id]))->values()->all();

        while ($pending !== []) {
            $sources = DB::table('sales_actual_items')->whereIntegerInRaw('id', $pending)->get($columns);

            foreach ($sources as $source) {
                $byId[$source->id] = $source;
            }

            $pending = $sources->pluck('source_sales_actual_item_id')->filter()->unique()->reject(fn ($id) => isset($byId[$id]))->values()->all();
        }

        $poiIds = collect($byId)->pluck('purchase_order_item_id')->filter()->unique()->values()->all();
        $poByPoi = $poiIds === [] ? [] : DB::table('purchase_order_items')->whereIntegerInRaw('id', $poiIds)->pluck('purchase_order_id', 'id')->all();
        // PO langsung di baris (Penjualan Barang Sisa ke customer lain, Porsi Tambahan).
        $poIds = array_values(array_unique(array_filter([...array_values($poByPoi), ...collect($byId)->pluck('purchase_order_id')->all()])));
        $purchaseOrders = $poIds === [] ? [] : DB::table('purchase_orders')->whereIntegerInRaw('id', $poIds)
            ->get(['id', 'po_number', 'recipient_name', 'shipping_cost', 'customer_id'])->keyBy('id')->all();
        $productIds = $items->pluck('product_id')->filter()->unique()->values()->all();
        $products = $productIds === [] ? [] : DB::table('products')->whereIntegerInRaw('id', $productIds)
            ->get(['id', 'name', 'is_3s'])->keyBy('id')->all();

        $resolvePo = function (object $row) use ($byId, $poByPoi, $purchaseOrders): ?object {
            $visited = [];

            while ($row && ! isset($visited[$row->id])) {
                $visited[$row->id] = true;
                $poId = $poByPoi[$row->purchase_order_item_id] ?? $row->purchase_order_id ?? null;

                if ($poId && isset($purchaseOrders[$poId])) {
                    return $purchaseOrders[$poId];
                }

                $row = $row->source_sales_actual_item_id ? ($byId[$row->source_sales_actual_item_id] ?? null) : null;
            }

            return null;
        };

        foreach ($items as $item) {
            $product = $products[$item->product_id] ?? null;
            $label = trim((string) ($item->item_name ?? ''));

            if ($label === '') {
                $label = trim((string) ($product->name ?? '')) ?: 'Item';
            }

            $lines[$item->sales_actual_id][] = [
                'label' => $label,
                'price' => round((float) $item->unit_price, 2),
                'qty' => (float) $item->qty_actual,
                'subtotal' => (float) $item->subtotal_actual,
                'is_3s' => (bool) ($product->is_3s ?? false),
                'purchase_order' => $resolvePo($item),
            ];
        }

        return $lines;
    }

    private function salesReportPriceKey(float $price): string
    {
        return 'price_'.str_replace('.', '_', number_format($price, 2, '.', ''));
    }

    private function salesReportPriceLabel(float $price): string
    {
        if (abs(fmod($price, 1000.0)) < 0.01) {
            return number_format($price / 1000, 0, ',', '.').'K';
        }

        return 'Rp '.number_format($price, 0, ',', '.');
    }

    private function parseDateRange(Request $request): array
    {
        try {
            $dateFrom = $request->input('date_from')
                ? Carbon::parse($request->input('date_from'))
                : now()->startOfDay();
        } catch (\Throwable) {
            $dateFrom = now()->startOfDay();
        }

        try {
            $dateTo = $request->input('date_to')
                ? Carbon::parse($request->input('date_to'))
                : now()->endOfDay();
        } catch (\Throwable) {
            $dateTo = now()->endOfDay();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }
}
