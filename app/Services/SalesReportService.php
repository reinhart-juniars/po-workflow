<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
            ->with([
                'customer:id,name,is_lapak',
                'items.product:id,name,is_3s',
                'items.purchaseOrderItem.purchaseOrder:id,po_number,recipient_name,shipping_cost,customer_id',
                'items.sourceSalesActualItem.purchaseOrderItem.purchaseOrder:id,po_number,recipient_name,shipping_cost,customer_id',
                'items.sourceSalesActualItem.sourceSalesActualItem.purchaseOrderItem.purchaseOrder:id,po_number,recipient_name,shipping_cost,customer_id',
            ])
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

        $salesActualTotal = fn (SalesActual $sa) => round((float) $sa->items->sum('subtotal_actual'), 2);
        $salesActualQty = fn (SalesActual $sa) => round((float) $sa->items->sum('qty_actual'), 2);

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
        ])->map(function (array $segment) use ($allActuals, $salesActualTotal, $salesActualQty) {
            $totalAmount = round((float) $segment['actuals']->sum($salesActualTotal), 2);
            $totalQty = (int) $segment['actuals']->sum($salesActualQty);
            $totalOrders = (int) $segment['actuals']->count();
            $allAmount = round((float) $allActuals->sum($salesActualTotal), 2);

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
            ->flatMap(function (SalesActual $sa) {
                return $sa->items->map(fn (SalesActualItem $item) => $this->salesReportItemLabel($item));
            })
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->values()
            ->all();

        $priceColumns = $actuals
            ->flatMap(function (SalesActual $sa) {
                return $sa->items->map(fn (SalesActualItem $item) => round((float) $item->unit_price, 2));
            })
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($price) => [
                'key' => $this->salesReportPriceKey((float) $price),
                'amount' => (float) $price,
                'label' => $this->salesReportPriceLabel((float) $price),
            ])
            ->all();

        $rowData = $actuals->map(function (SalesActual $sa) use ($itemColumns, $priceColumns) {
            $itemQtyMap = collect($itemColumns)->mapWithKeys(fn ($itemLabel) => [$itemLabel => 0])->all();
            $priceAmountMap = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();
            $priceQtyMap = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0])->all();
            $hasThreeS = false;

            foreach ($sa->items as $item) {
                $itemLabel = $this->salesReportItemLabel($item);
                $priceKey = $this->salesReportPriceKey((float) $item->unit_price);
                $qtyActual = (float) $item->qty_actual;
                $subtotalActual = (float) $item->subtotal_actual;

                $itemQtyMap[$itemLabel] = ($itemQtyMap[$itemLabel] ?? 0) + $qtyActual;
                $priceAmountMap[$priceKey] = ($priceAmountMap[$priceKey] ?? 0) + $subtotalActual;
                $priceQtyMap[$priceKey] = ($priceQtyMap[$priceKey] ?? 0) + $qtyActual;

                if ((bool) ($item->product?->is_3s ?? false)) {
                    $hasThreeS = true;
                }
            }

            $purchaseOrders = $sa->items
                ->map(fn (SalesActualItem $item) => $this->resolvePurchaseOrderForItem($item))
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
                ?? ('SA ' . $sa->id);

            $poLine = $poNumbers->isNotEmpty() ? $poNumbers->implode(', ') : null;
            $recipientLine = $recipientNames->isNotEmpty() ? 'Penerima: ' . $recipientNames->implode(', ') : null;

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
                'total_qty' => (int) $sa->items->sum('qty_actual'),
                'shipping_cost' => $shippingCost,
                'total_amount' => round((float) $sa->items->sum('subtotal_actual'), 2),
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
                $itemTotals = collect($itemColumns)->mapWithKeys(fn ($itemLabel) => [$itemLabel => 0])->all();
                $priceTotals = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();
                $priceQtyTotals = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0])->all();

                foreach ($rows as $row) {
                    foreach ($itemColumns as $itemLabel) {
                        $itemTotals[$itemLabel] += (int) ($row['item_qty_map'][$itemLabel] ?? 0);
                    }

                    foreach ($priceColumns as $price) {
                        $priceTotals[$price['key']] += (float) ($row['price_amount_map'][$price['key']] ?? 0);
                        $priceQtyTotals[$price['key']] += (int) ($row['price_qty_map'][$price['key']] ?? 0);
                    }
                }

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

                    $segmentItemTotals = collect($itemColumns)->mapWithKeys(fn ($itemLabel) => [$itemLabel => 0])->all();
                    $segmentPriceTotals = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();

                    foreach ($segmentRows as $segmentRow) {
                        foreach ($itemColumns as $itemLabel) {
                            $segmentItemTotals[$itemLabel] += (int) ($segmentRow['item_qty_map'][$itemLabel] ?? 0);
                        }

                        foreach ($priceColumns as $price) {
                            $segmentPriceTotals[$price['key']] += (float) ($segmentRow['price_amount_map'][$price['key']] ?? 0);
                        }
                    }

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

        $grandItemTotals = collect($itemColumns)->mapWithKeys(fn ($itemLabel) => [$itemLabel => 0])->all();
        $grandPriceTotals = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();
        $grandPriceQtyTotals = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0])->all();

        foreach ($rowData as $row) {
            foreach ($itemColumns as $itemLabel) {
                $grandItemTotals[$itemLabel] += (int) ($row['item_qty_map'][$itemLabel] ?? 0);
            }

            foreach ($priceColumns as $price) {
                $grandPriceTotals[$price['key']] += (float) ($row['price_amount_map'][$price['key']] ?? 0);
                $grandPriceQtyTotals[$price['key']] += (int) ($row['price_qty_map'][$price['key']] ?? 0);
            }
        }

        $grandSegmentBreakdown = collect($segmentKeys)->map(function (string $label, string $key) use (
            $rowData,
            $itemColumns,
            $priceColumns,
            $grandTotalSales
        ) {
            $segmentRows = $rowData
                ->filter(fn ($row) => $key === 'lapak' ? (bool) ($row['is_lapak'] ?? false) : ! (bool) ($row['is_lapak'] ?? false))
                ->values();

            $segmentItemTotals = collect($itemColumns)->mapWithKeys(fn ($itemLabel) => [$itemLabel => 0])->all();
            $segmentPriceTotals = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();

            foreach ($segmentRows as $segmentRow) {
                foreach ($itemColumns as $itemLabel) {
                    $segmentItemTotals[$itemLabel] += (int) ($segmentRow['item_qty_map'][$itemLabel] ?? 0);
                }

                foreach ($priceColumns as $price) {
                    $segmentPriceTotals[$price['key']] += (float) ($segmentRow['price_amount_map'][$price['key']] ?? 0);
                }
            }

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

            $sectionPriceQty = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0])->all();
            $sectionPriceAmount = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();

            foreach ($sectionRows as $row) {
                foreach ($priceColumns as $price) {
                    $sectionPriceQty[$price['key']] += (int) ($row['price_qty_map'][$price['key']] ?? 0);
                    $sectionPriceAmount[$price['key']] += (float) ($row['price_amount_map'][$price['key']] ?? 0);
                }
            }

            $groups = $sectionRows
                ->groupBy('date_key')
                ->map(function ($rows, $dateKey) use ($priceColumns, $grandTotalSales, $sectionTotal) {
                    $priceQty = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0])->all();
                    $priceAmount = collect($priceColumns)->mapWithKeys(fn ($price) => [$price['key'] => 0.0])->all();

                    foreach ($rows as $row) {
                        foreach ($priceColumns as $price) {
                            $priceQty[$price['key']] += (int) ($row['price_qty_map'][$price['key']] ?? 0);
                            $priceAmount[$price['key']] += (float) ($row['price_amount_map'][$price['key']] ?? 0);
                        }
                    }

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

    private function salesReportItemLabel($item): string
    {
        $itemName = trim((string) ($item->item_name ?? ''));

        if ($itemName !== '') {
            return $itemName;
        }

        $customName = trim((string) ($item->custom_name ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        $productName = trim((string) ($item->product->name ?? ''));

        if ($productName !== '') {
            return $productName;
        }

        return 'Item';
    }

    private function resolvePurchaseOrderForItem(SalesActualItem $item, array $visited = []): ?PurchaseOrder
    {
        if (in_array($item->id, $visited, true)) {
            return null;
        }

        $visited[] = $item->id;

        $purchaseOrder = $item->purchaseOrderItem?->purchaseOrder;

        if ($purchaseOrder) {
            return $purchaseOrder;
        }

        if ($item->sourceSalesActualItem) {
            return $this->resolvePurchaseOrderForItem($item->sourceSalesActualItem, $visited);
        }

        return null;
    }

    private function salesReportPriceKey(float $price): string
    {
        return 'price_' . str_replace('.', '_', number_format($price, 2, '.', ''));
    }

    private function salesReportPriceLabel(float $price): string
    {
        if (abs(fmod($price, 1000.0)) < 0.01) {
            return number_format($price / 1000, 0, ',', '.') . 'K';
        }

        return 'Rp ' . number_format($price, 0, ',', '.');
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
