<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PurchaseOrder;
use App\Models\Spk;
use App\Models\DeliveryOrder;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\OtherIncome;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\UiLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OwnerAppController extends Controller
{
    /**
     * Markup standar yang dipakai klien untuk menghitung profit "Hitungan".
     * Profit Hitungan (Rp) = (Bahan Baku Hitungan + OHC Hitungan) x markup ini,
     * lalu Profit % = Profit Hitungan / Sales Actual (jadi variatif per periode, di atas 20%).
     */
    private const HPP_MARKUP_RATE = 0.307;

    /**
     * Dashboard ringkasan (boleh tetap pakai view lama kamu)
     * Di sini aku pakai filter tanggal + status sederhana.
     */
    public function dashboard(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDashboardDateRange($request);
        $daysInRange = max($dateFrom->diffInDays($dateTo) + 1, 1);
        $previousDateFrom = $dateFrom->copy()->subMonthNoOverflow();
        $previousDateTo = $dateTo->copy()->subMonthNoOverflow();

        $salesQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ]);

        $cashInOrdersQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at')
            ->whereBetween('cash_received_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ]);

        $otherIncomeQuery = OtherIncome::query()
            ->whereBetween('income_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]);

        $expenseQuery = CashOut::query()
            ->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]);

        $hppQuery = CashOut::query()
            ->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->whereHas('category', function ($query) {
                $query->where('include_hpp', true);
            });

        $openReceivables = PurchaseOrder::query()
            ->with('customer:id,name')
            ->openReceivable()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->get(['id', 'customer_id', 'due_date', 'total_amount', 'completed_at']);

        $totalSales = round((float) (clone $salesQuery)->sum('total_amount'), 2);
        // Ongkir dipisah ke OtherIncome (Penjualan Lain-Lain), jadi penerimaan PO di sini
        // cuma porsi principal supaya tidak double-count.
        $cashInFromOrders = round((float) (clone $cashInOrdersQuery)
            ->sum(DB::raw('(COALESCE(total_amount, 0) - COALESCE(shipping_cost, 0))')), 2);
        $cashInFromOtherIncome = round((float) (clone $otherIncomeQuery)->sum('amount'), 2);
        $totalCashIn = round($cashInFromOrders + $cashInFromOtherIncome, 2);
        $salesActualItemsQuery = fn () => DB::table('sales_actual_items')
            ->join('sales_actuals', 'sales_actuals.id', '=', 'sales_actual_items.sales_actual_id')
            ->where('sales_actuals.status', 'submitted')
            ->whereBetween('sales_actuals.submitted_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ]);
        $salesActualTotals = $salesActualItemsQuery()
            ->selectRaw('COALESCE(SUM(sales_actual_items.qty_delivery * sales_actual_items.unit_price), 0) as delivery_value')
            ->selectRaw('COALESCE(SUM(sales_actual_items.subtotal_actual), 0) as actual_value')
            ->first();
        $salesDeliveryValue = round((float) ($salesActualTotals->delivery_value ?? 0), 2);
        $salesActualValue = round((float) ($salesActualTotals->actual_value ?? 0), 2);
        $salesActualGap = round($salesDeliveryValue - $salesActualValue, 2);
        $salesActualRate = $salesDeliveryValue > 0
            ? round(($salesActualValue / $salesDeliveryValue) * 100, 2)
            : null;
        $totalExpense = round((float) (clone $expenseQuery)->sum('amount'), 2);
        $totalHpp = round((float) (clone $hppQuery)->sum('amount'), 2);
        $outstandingReceivable = round((float) $openReceivables->sum('total_amount'), 2);
        $estimatedMargin = round($totalSales - $totalHpp, 2);
        $netCashflow = round($totalCashIn - $totalExpense, 2);
        $avgDailySales = round($totalSales / $daysInRange, 2);
        $transactionCount = (clone $salesQuery)->count();

        $previousSales = round((float) PurchaseOrder::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                $previousDateFrom->copy()->startOfDay(),
                $previousDateTo->copy()->endOfDay(),
            ])
            ->sum('total_amount'), 2);

        $growthVsPreviousMonth = $previousSales > 0
            ? round((($totalSales - $previousSales) / $previousSales) * 100, 2)
            : null;

        [$periods, $granularity] = $this->makeDashboardPeriods($dateFrom, $dateTo);

        $salesByPeriod = $this->groupTotalsByPeriod(
            PurchaseOrder::query()
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [
                    $dateFrom->copy()->startOfDay(),
                    $dateTo->copy()->endOfDay(),
                ]),
            'completed_at',
            'total_amount',
            $granularity
        );

        $expenseByPeriod = $this->groupTotalsByPeriod(
            CashOut::query()->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]),
            'expense_date',
            'amount',
            $granularity
        );

        $salesDeliveryByPeriod = $this->groupTotalsByPeriod(
            $salesActualItemsQuery(),
            'sales_actuals.submitted_at',
            'sales_actual_items.qty_delivery * sales_actual_items.unit_price',
            $granularity
        );

        $salesActualByPeriod = $this->groupTotalsByPeriod(
            $salesActualItemsQuery(),
            'sales_actuals.submitted_at',
            'sales_actual_items.subtotal_actual',
            $granularity
        );

        $salesVsExpenseChart = $this->buildLineChartPayload($periods, [
            [
                'name' => 'Penjualan',
                'color' => '#1d4ed8',
                'values' => $this->seriesValuesFromPeriods($periods, $salesByPeriod),
            ],
            [
                'name' => 'Pengeluaran',
                'color' => '#e11d48',
                'values' => $this->seriesValuesFromPeriods($periods, $expenseByPeriod),
            ],
        ]);

        $salesDeliveryActualChart = $this->buildLineChartPayload($periods, [
            [
                'name' => 'Sales Delivery',
                'color' => '#1d4ed8',
                'values' => $this->seriesValuesFromPeriods($periods, $salesDeliveryByPeriod),
            ],
            [
                'name' => 'Sales Actual',
                'color' => '#0f766e',
                'values' => $this->seriesValuesFromPeriods($periods, $salesActualByPeriod),
            ],
        ]);

        $expenseCategoryBreakdown = CashOut::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'cash_outs.expense_category_id')
            ->whereBetween('cash_outs.expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->selectRaw("COALESCE(NULLIF(TRIM(expense_categories.name), ''), 'Tanpa Kategori') as category_name")
            ->selectRaw('SUM(cash_outs.amount) as total_amount')
            ->groupByRaw("COALESCE(NULLIF(TRIM(expense_categories.name), ''), 'Tanpa Kategori')")
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->category_name,
                'value' => round((float) $row->total_amount, 2),
            ])
            ->values();

        $totalExpenseByCategory = round((float) $expenseCategoryBreakdown->sum('value'), 2);
        $largestExpenseCategory = $expenseCategoryBreakdown->first();
        $topExpenseCategories = $expenseCategoryBreakdown
            ->take(3)
            ->map(function (array $category) use ($totalExpenseByCategory) {
                $value = (float) $category['value'];

                return [
                    'label' => $category['label'],
                    'value' => round($value, 2),
                    'percentage' => $totalExpenseByCategory > 0
                        ? round(($value / $totalExpenseByCategory) * 100, 2)
                        : 0,
                ];
            })
            ->values();

        if ($largestExpenseCategory) {
            $largestExpenseCategory['percentage'] = $totalExpenseByCategory > 0
                ? round((((float) $largestExpenseCategory['value']) / $totalExpenseByCategory) * 100, 2)
                : 0;
        }

        $expenseCategoryChart = $this->buildDonutChartPayload(
            $expenseCategoryBreakdown->all(),
            (int) $expenseCategoryBreakdown->count(),
            $largestExpenseCategory,
            $topExpenseCategories->all()
        );

        $today = now()->startOfDay();
        $receivableAging = collect([
            [
                'label' => 'Belum jatuh tempo',
                'amount' => 0.0,
                'count' => 0,
                'tone' => 'slate',
            ],
            [
                'label' => '0-7 hari',
                'amount' => 0.0,
                'count' => 0,
                'tone' => 'amber',
            ],
            [
                'label' => '8-14 hari',
                'amount' => 0.0,
                'count' => 0,
                'tone' => 'orange',
            ],
            [
                'label' => '>14 hari',
                'amount' => 0.0,
                'count' => 0,
                'tone' => 'rose',
            ],
        ]);

        foreach ($openReceivables as $receivable) {
            $bucketIndex = 0;
            $dueDate = $receivable->due_date ? Carbon::parse($receivable->due_date)->startOfDay() : null;

            if ($dueDate && $dueDate->lt($today)) {
                $daysLate = $dueDate->diffInDays($today);
                $bucketIndex = $daysLate <= 7 ? 1 : ($daysLate <= 14 ? 2 : 3);
            }

            $bucket = $receivableAging->get($bucketIndex);
            $bucket['amount'] += (float) $receivable->total_amount;
            $bucket['count'] += 1;
            $receivableAging->put($bucketIndex, $bucket);
        }

        $maxAgingAmount = max(1, (float) $receivableAging->max('amount'));
        $receivableAging = $receivableAging
            ->map(function (array $bucket) use ($maxAgingAmount) {
                $bucket['amount'] = round((float) $bucket['amount'], 2);
                $bucket['bar_width'] = round(((float) $bucket['amount'] / $maxAgingAmount) * 100, 2);

                return $bucket;
            })
            ->values();

        $topProducts = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_orders.status', 'completed')
            ->whereNotNull('purchase_orders.completed_at')
            ->whereBetween('purchase_orders.completed_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->selectRaw("COALESCE(NULLIF(TRIM(purchase_order_items.custom_name), ''), products.name, CONCAT('Produk #', purchase_order_items.product_id)) as item_name")
            ->selectRaw('SUM(purchase_order_items.qty) as total_qty')
            ->selectRaw('SUM(purchase_order_items.subtotal) as total_sales')
            ->groupByRaw("COALESCE(NULLIF(TRIM(purchase_order_items.custom_name), ''), products.name, CONCAT('Produk #', purchase_order_items.product_id))")
            ->orderByDesc('total_qty')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        $maxTopProductQty = max(1, (float) $topProducts->max('total_qty'));
        $topProducts = $topProducts->map(function ($item) use ($maxTopProductQty) {
            $item->bar_width = round((((float) $item->total_qty) / $maxTopProductQty) * 100, 2);

            return $item;
        });

        $topCustomers = DB::table('purchase_orders')
            ->leftJoin('customers', 'customers.id', '=', 'purchase_orders.customer_id')
            ->where('purchase_orders.status', 'completed')
            ->whereNotNull('purchase_orders.completed_at')
            ->whereBetween('purchase_orders.completed_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->selectRaw("COALESCE(customers.name, 'Tanpa Customer') as customer_name")
            ->selectRaw('SUM(purchase_orders.total_amount) as total_sales')
            ->selectRaw('COUNT(*) as total_orders')
            ->groupByRaw("COALESCE(customers.name, 'Tanpa Customer')")
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        $maxTopCustomerSales = max(1, (float) $topCustomers->max('total_sales'));
        $topCustomers = $topCustomers->map(function ($customer) use ($maxTopCustomerSales) {
            $customer->bar_width = round((((float) $customer->total_sales) / $maxTopCustomerSales) * 100, 2);

            return $customer;
        });

        $cashHealth = $this->buildSignalCardData($totalCashIn, $totalExpense, 'cash');
        $profitSignal = $this->buildSignalCardData($totalSales, $totalHpp, 'profit');
        $hppCategoryCount = ExpenseCategory::query()->where('include_hpp', true)->count();

        return view('ownerapp.dashboard', [
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo' => $dateTo->toDateString(),
            'periodLabel' => $this->formatDateRangeLabel($dateFrom, $dateTo),
            'periodDays' => $daysInRange,
            'granularityLabel' => $granularity === 'day' ? 'harian' : 'bulanan',
            'comparisonLabel' => $this->formatDateRangeLabel($previousDateFrom, $previousDateTo),
            'totalSales' => $totalSales,
            'totalCashIn' => $totalCashIn,
            'salesDeliveryValue' => $salesDeliveryValue,
            'salesActualValue' => $salesActualValue,
            'salesActualGap' => $salesActualGap,
            'salesActualRate' => $salesActualRate,
            'totalExpense' => $totalExpense,
            'netCashflow' => $netCashflow,
            'outstandingReceivable' => $outstandingReceivable,
            'estimatedMargin' => $estimatedMargin,
            'totalHpp' => $totalHpp,
            'avgDailySales' => $avgDailySales,
            'transactionCount' => $transactionCount,
            'growthVsPreviousMonth' => $growthVsPreviousMonth,
            'cashHealth' => $cashHealth,
            'profitSignal' => $profitSignal,
            'expenseCategoryChart' => $expenseCategoryChart,
            'salesVsExpenseChart' => $salesVsExpenseChart,
            'salesDeliveryActualChart' => $salesDeliveryActualChart,
            'receivableAging' => $receivableAging,
            'receivableCount' => $openReceivables->count(),
            'topProducts' => $topProducts,
            'topCustomers' => $topCustomers,
            'hppCategoryCount' => $hppCategoryCount,
        ]);
    }

    //  LAPORAN PO
    public function ordersReport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $ordersQuery = PurchaseOrder::with(['customer','area'])
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();

        return view('ownerapp.reports.orders', [
            'orders'      => $orders,
            'ordersCount' => $orders->count(),
            'dateFrom'    => $dateFrom->toDateString(),
            'dateTo'      => $dateTo->toDateString(),
            'status'      => $status,
        ]);
    }

    public function exportOrdersExcel(Request $request): StreamedResponse
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $ordersQuery = PurchaseOrder::with(['customer','area'])
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();

        $fileName = 'laporan_po_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($orders) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['PO Number', 'Customer', 'Area', 'Status', 'Created At']);

            foreach ($orders as $o) {
                fputcsv($handle, [
                    $o->po_number,
                    optional($o->customer)->name,
                    optional($o->area)->name,
                    UiLabel::purchaseOrderStatus($o->status),
                    optional($o->created_at)?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportOrdersPdf(Request $request)
    {
        // Untuk saat ini: pakai view print-friendly, bisa Save as PDF dari browser.
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $ordersQuery = PurchaseOrder::with(['customer','area'])
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();

        return view('ownerapp.reports.orders_pdf', [
            'orders'      => $orders,
            'dateFrom'    => $dateFrom->toDateString(),
            'dateTo'      => $dateTo->toDateString(),
            'status'      => $status,
        ]);
    }

    // LAPORAN PRODUKSI (SPK)
    public function productionReport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $spkQuery = Spk::whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $spkQuery->where('status', $status);
        }

        $spks = $spkQuery->orderByDesc('scheduled_at')->get();

        return view('ownerapp.reports.production', [
            'spks'     => $spks,
            'spkCount' => $spks->count(),
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
            'status'   => $status,
        ]);
    }

    public function exportProductionExcel(Request $request): StreamedResponse
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $spkQuery = Spk::whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $spkQuery->where('status', $status);
        }

        $spks = $spkQuery->orderByDesc('scheduled_at')->get();

        $fileName = 'laporan_produksi_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($spks) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['SPK Code', 'Slot', 'Scheduled At', 'Status', 'Created At']);

            foreach ($spks as $s) {
                fputcsv($handle, [
                    $s->spk_code,
                    UiLabel::spkSlot($s->slot_type),
                    optional($s->scheduled_at)?->format('Y-m-d H:i:s'),
                    UiLabel::spkStatus($s->status),
                    optional($s->created_at)?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportProductionPdf(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $spkQuery = Spk::whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $spkQuery->where('status', $status);
        }

        $spks = $spkQuery->orderByDesc('scheduled_at')->get();

        return view('ownerapp.reports.production_pdf', [
            'spks'     => $spks,
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
            'status'   => $status,
        ]);
    }

    // LAPORAN DELIVERY
    public function deliveryReport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $doQuery = DeliveryOrder::with(['area','driver'])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $doQuery->where('status', $status);
        }

        $dos = $doQuery->orderByDesc('scheduled_at')->get();

        return view('ownerapp.reports.delivery', [
            'dos'      => $dos,
            'doCount'  => $dos->count(),
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
            'status'   => $status,
        ]);
    }

    public function exportDeliveryExcel(Request $request): StreamedResponse
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $doQuery = DeliveryOrder::with(['area','driver'])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $doQuery->where('status', $status);
        }

        $dos = $doQuery->orderByDesc('scheduled_at')->get();

        $fileName = 'laporan_delivery_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($dos) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['DO Code', 'Area', 'Driver', 'Recipient', 'Status', 'Scheduled At']);

            foreach ($dos as $d) {
                fputcsv($handle, [
                    $d->do_code,
                    optional($d->area)->name,
                    optional($d->driver)->name,
                    $d->recipient_name,
                    UiLabel::deliveryStatus($d->status),
                    optional($d->scheduled_at)?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportDeliveryPdf(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $doQuery = DeliveryOrder::with(['area','driver'])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $doQuery->where('status', $status);
        }

        $dos = $doQuery->orderByDesc('scheduled_at')->get();

        return view('ownerapp.reports.delivery_pdf', [
            'dos'      => $dos,
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
            'status'   => $status,
        ]);
    }

    // AUDIT LOGS
    public function auditIndex(Request $request)
    {
        // Pakai helper tanggal yang sudah ada
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $userId = $request->input('user_id');
        $entity = $request->input('entity');
        $action = $request->input('action');

        $logsQuery = AuditLog::with('user')
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($userId) {
            $logsQuery->where('user_id', $userId);
        }

        if ($entity) {
            $logsQuery->where('entity', $entity);
        }

        if ($action) {
            $logsQuery->where('action', $action);
        }

        $logs = $logsQuery
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        // Untuk dropdown filter
        $users = User::whereDoesntHave('roles', function ($q) {
        $q->where('name', 'superadmin');
        })
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

        $availableEntities = AuditLog::select('entity')
            ->distinct()
            ->pluck('entity')
            ->filter()
            ->values();

        $availableActions = AuditLog::select('action')
            ->distinct()
            ->pluck('action')
            ->filter()
            ->values();

        return view('ownerapp.audit.index', [
            'logs'             => $logs,
            'users'            => $users,
            'availableEntities'=> $availableEntities,
            'availableActions' => $availableActions,
            'dateFrom'         => $dateFrom->toDateString(),
            'dateTo'           => $dateTo->toDateString(),
            'userId'           => $userId,
            'entity'           => $entity,
            'action'           => $action,
        ]);
    }

    public function hppAnalysis(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseAnalysisDateRange($request);

        $hppNames = ['bahan baku', 'kemasan'];
        $excludedFromOhcNames = ['bahan baku', 'kemasan', 'inventaris'];

        $salesQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ]);

        $totalSales = round((float) (clone $salesQuery)->sum('total_amount'), 2);

        $salesPerPeriod = (clone $salesQuery)
            ->selectRaw('DATE_FORMAT(completed_at, "%Y-%m") as period, COALESCE(SUM(total_amount), 0) as total_sales')
            ->groupBy('period')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->period => (float) $row->total_sales]);

        $expenseTotals = CashOut::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'cash_outs.expense_category_id')
            ->whereBetween('cash_outs.expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN LOWER(expense_categories.name) IN (?, ?) THEN cash_outs.amount ELSE 0 END), 0) as hpp_real',
                $hppNames
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(COALESCE(expense_categories.name, '')) NOT IN (?, ?, ?) THEN cash_outs.amount ELSE 0 END), 0) as ohc_real",
                $excludedFromOhcNames
            )
            ->first();

        $totalHppReal = round((float) ($expenseTotals->hpp_real ?? 0), 2);
        $totalOhcReal = round((float) ($expenseTotals->ohc_real ?? 0), 2);

        $salesActualTotals = DB::table('sales_actual_items')
            ->join('sales_actuals', 'sales_actuals.id', '=', 'sales_actual_items.sales_actual_id')
            ->leftJoin('products', 'products.id', '=', 'sales_actual_items.product_id')
            ->where('sales_actuals.status', 'submitted')
            ->whereBetween('sales_actuals.submitted_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->selectRaw('COALESCE(SUM(sales_actual_items.subtotal_actual), 0) as revenue_actual')
            ->selectRaw('COALESCE(SUM(COALESCE(sales_actual_items.raw_material_cost, products.raw_material_cost, 0) * sales_actual_items.qty_actual), 0) as hpp_jual')
            ->selectRaw('COALESCE(SUM(COALESCE(sales_actual_items.overhead_cost, products.overhead_cost, 0) * sales_actual_items.qty_actual), 0) as ohc_jual')
            ->first();

        $totalSalesActual = round((float) ($salesActualTotals->revenue_actual ?? 0), 2);
        $totalHppJual = round((float) ($salesActualTotals->hpp_jual ?? 0), 2);
        $totalOhcJual = round((float) ($salesActualTotals->ohc_jual ?? 0), 2);
        // Profit Hitungan = markup standar atas total cost hitungan (BB + OHC); persen-nya vs Sales Actual.
        $totalProfitJual = round(($totalHppJual + $totalOhcJual) * self::HPP_MARKUP_RATE, 2);
        $totalProfitPercent = $totalSalesActual > 0 ? round(($totalProfitJual / $totalSalesActual) * 100, 2) : null;
        // Selisih: BB = Real - Hitungan, OHC = Real - Hitungan.
        $totalSelisihBb = round($totalHppReal - $totalHppJual, 2);
        $totalSelisihOhc = round($totalOhcReal - $totalOhcJual, 2);

        // Profit Real diambil langsung dari laporan Laba Rugi (versi "FinalStyle" yang jadi figur Laba).
        $inventoryUsageService = app(\App\Services\InventoryUsageService::class);
        $profitLossController = app(ProfitLossReportController::class);
        $totalFinalStyle = $profitLossController->finalStyleProfitLossForRange($dateFrom, $dateTo, $inventoryUsageService);
        $totalProfitReal = round((float) ($totalFinalStyle['labaRugi'] ?? 0), 2);
        $totalProfitRealRevenue = (float) ($totalFinalStyle['totalPenjualan'] ?? 0);
        $totalProfitRealPercent = $totalProfitRealRevenue > 0
            ? round(($totalProfitReal / $totalProfitRealRevenue) * 100, 2)
            : null;

        $expensePerPeriod = CashOut::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'cash_outs.expense_category_id')
            ->whereBetween('cash_outs.expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ])
            ->selectRaw('DATE_FORMAT(cash_outs.expense_date, "%Y-%m") as period')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN LOWER(expense_categories.name) IN (?, ?) THEN cash_outs.amount ELSE 0 END), 0) as hpp_real',
                $hppNames
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(COALESCE(expense_categories.name, '')) NOT IN (?, ?, ?) THEN cash_outs.amount ELSE 0 END), 0) as ohc_real",
                $excludedFromOhcNames
            )
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $salesActualPerPeriod = DB::table('sales_actual_items')
            ->join('sales_actuals', 'sales_actuals.id', '=', 'sales_actual_items.sales_actual_id')
            ->leftJoin('products', 'products.id', '=', 'sales_actual_items.product_id')
            ->where('sales_actuals.status', 'submitted')
            ->whereBetween('sales_actuals.submitted_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->selectRaw('DATE_FORMAT(sales_actuals.submitted_at, "%Y-%m") as period')
            ->selectRaw('COALESCE(SUM(sales_actual_items.subtotal_actual), 0) as revenue_actual')
            ->selectRaw('COALESCE(SUM(COALESCE(sales_actual_items.raw_material_cost, products.raw_material_cost, 0) * sales_actual_items.qty_actual), 0) as hpp_jual')
            ->selectRaw('COALESCE(SUM(COALESCE(sales_actual_items.overhead_cost, products.overhead_cost, 0) * sales_actual_items.qty_actual), 0) as ohc_jual')
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $periodRows = [];
        $cursor = $dateFrom->copy()->startOfMonth();
        $lastPeriod = $dateTo->copy()->startOfMonth();

        while ($cursor->lte($lastPeriod)) {
            $periodKey = $cursor->format('Y-m');
            $expenseRow = $expensePerPeriod->get($periodKey);
            $salesActualRow = $salesActualPerPeriod->get($periodKey);
            $periodSales = round((float) ($salesPerPeriod[$periodKey] ?? 0), 2);
            $periodRevenueActual = round((float) ($salesActualRow->revenue_actual ?? 0), 2);

            $periodHppReal = round((float) ($expenseRow->hpp_real ?? 0), 2);
            $periodOhcReal = round((float) ($expenseRow->ohc_real ?? 0), 2);
            $periodHppJual = round((float) ($salesActualRow->hpp_jual ?? 0), 2);
            $periodOhcJual = round((float) ($salesActualRow->ohc_jual ?? 0), 2);
            $periodProfitJual = round(($periodHppJual + $periodOhcJual) * self::HPP_MARKUP_RATE, 2);
            $periodProfitPercent = $periodRevenueActual > 0 ? round(($periodProfitJual / $periodRevenueActual) * 100, 2) : null;
            $periodSelisihBb = round($periodHppReal - $periodHppJual, 2);
            $periodSelisihOhc = round($periodOhcReal - $periodOhcJual, 2);

            // Profit Real per bulan = figur Laba dari laporan Laba Rugi (FinalStyle) pada bulan tersebut.
            $periodFinalStyle = $profitLossController->finalStyleProfitLossForRange(
                $cursor->copy()->startOfMonth(),
                $cursor->copy()->endOfMonth(),
                $inventoryUsageService
            );
            $periodProfitReal = round((float) ($periodFinalStyle['labaRugi'] ?? 0), 2);
            $periodProfitRealRevenue = (float) ($periodFinalStyle['totalPenjualan'] ?? 0);
            $periodProfitRealPercent = $periodProfitRealRevenue > 0
                ? round(($periodProfitReal / $periodProfitRealRevenue) * 100, 2)
                : null;

            $periodRows[] = [
                'period' => $periodKey,
                'label' => $this->monthLabel((int) $cursor->month) . ' ' . $cursor->year,
                'sales' => $periodSales,
                'revenue_actual' => $periodRevenueActual,
                'hpp_real' => $periodHppReal,
                'ohc_real' => $periodOhcReal,
                'hpp_jual' => $periodHppJual,
                'ohc_jual' => $periodOhcJual,
                'selisih_bb' => $periodSelisihBb,
                'selisih_ohc' => $periodSelisihOhc,
                'profit_jual' => $periodProfitJual,
                'profit_real' => $periodProfitReal,
                'profit_percent' => $periodProfitPercent,
                'profit_real_percent' => $periodProfitRealPercent,
            ];

            $cursor->addMonth();
        }

        return view('ownerapp.hpp-analysis', [
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo' => $dateTo->toDateString(),
            'totalSales' => $totalSales,
            'totalSalesActual' => $totalSalesActual,
            'totalHppReal' => $totalHppReal,
            'totalOhcReal' => $totalOhcReal,
            'totalHppJual' => $totalHppJual,
            'totalOhcJual' => $totalOhcJual,
            'totalSelisihBb' => $totalSelisihBb,
            'totalSelisihOhc' => $totalSelisihOhc,
            'totalProfitJual' => $totalProfitJual,
            'totalProfitReal' => $totalProfitReal,
            'totalProfitPercent' => $totalProfitPercent,
            'totalProfitRealPercent' => $totalProfitRealPercent,
            'periodRows' => collect($periodRows),
        ]);
    }

    protected function parseDashboardDateRange(Request $request): array
    {
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        try {
            $dateFrom = $from ? Carbon::parse($from) : now()->startOfMonth();
        } catch (\Exception $e) {
            $dateFrom = now()->startOfMonth();
        }

        try {
            $dateTo = $to ? Carbon::parse($to) : now()->endOfMonth();
        } catch (\Exception $e) {
            $dateTo = now()->endOfMonth();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    protected function makeDashboardPeriods(Carbon $dateFrom, Carbon $dateTo): array
    {
        $granularity = $dateFrom->diffInDays($dateTo) <= 45 ? 'day' : 'month';
        $periods = [];

        $cursor = $granularity === 'day'
            ? $dateFrom->copy()->startOfDay()
            : $dateFrom->copy()->startOfMonth();

        $end = $granularity === 'day'
            ? $dateTo->copy()->startOfDay()
            : $dateTo->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $periods[] = [
                'key' => $granularity === 'day' ? $cursor->toDateString() : $cursor->format('Y-m'),
                'label' => $granularity === 'day'
                    ? $cursor->format('d') . ' ' . $this->shortMonthLabel((int) $cursor->month)
                    : $this->shortMonthLabel((int) $cursor->month),
                'full_label' => $granularity === 'day'
                    ? $cursor->format('d') . ' ' . $this->monthLabel((int) $cursor->month) . ' ' . $cursor->year
                    : $this->monthLabel((int) $cursor->month) . ' ' . $cursor->year,
            ];

            $granularity === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        return [$periods, $granularity];
    }

    protected function groupTotalsByPeriod($query, string $dateColumn, string $sumColumn, string $granularity)
    {
        $periodExpression = $granularity === 'day'
            ? "DATE({$dateColumn})"
            : "DATE_FORMAT({$dateColumn}, '%Y-%m')";

        return $query
            ->selectRaw("{$periodExpression} as period_key, SUM({$sumColumn}) as aggregate_total")
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->period_key => round((float) $row->aggregate_total, 2)]);
    }

    protected function seriesValuesFromPeriods(array $periods, $map): array
    {
        return collect($periods)
            ->map(fn (array $period) => round((float) ($map[$period['key']] ?? 0), 2))
            ->all();
    }

    protected function buildLineChartPayload(array $periods, array $series): array
    {
        $width = 720;
        $height = 260;
        $paddingLeft = 18;
        $paddingRight = 18;
        $paddingTop = 16;
        $paddingBottom = 40;
        $plotWidth = $width - $paddingLeft - $paddingRight;
        $plotHeight = $height - $paddingTop - $paddingBottom;
        $pointCount = max(count($periods), 1);
        $stepX = $pointCount > 1 ? $plotWidth / ($pointCount - 1) : 0;
        $maxValue = collect($series)
            ->flatMap(fn (array $item) => $item['values'])
            ->map(fn ($value) => (float) $value)
            ->max() ?? 0;

        $chartMax = $this->chartCeiling($maxValue);

        $normalizedSeries = collect($series)->map(function (array $item) use (
            $paddingLeft,
            $paddingTop,
            $plotHeight,
            $plotWidth,
            $stepX,
            $pointCount,
            $chartMax
        ) {
            $points = collect($item['values'])->values()->map(function ($value, $index) use (
                $paddingLeft,
                $paddingTop,
                $plotHeight,
                $plotWidth,
                $stepX,
                $pointCount,
                $chartMax
            ) {
                $x = $pointCount === 1
                    ? $paddingLeft + ($plotWidth / 2)
                    : $paddingLeft + ($index * $stepX);
                $y = $paddingTop + $plotHeight - (((float) $value / $chartMax) * $plotHeight);

                return [
                    'x' => round((float) $x, 2),
                    'y' => round((float) $y, 2),
                ];
            })->values();

            $item['points'] = $points
                ->map(fn (array $point) => $point['x'] . ',' . $point['y'])
                ->implode(' ');

            $item['last_point'] = $points->last();

            return $item;
        })->values();

        $yTicks = collect(range(0, 4))->map(function (int $step) use (
            $chartMax,
            $paddingTop,
            $plotHeight
        ) {
            $ratio = $step / 4;
            $value = $chartMax * (1 - $ratio);
            $y = $paddingTop + ($plotHeight * $ratio);

            return [
                'y' => round((float) $y, 2),
                'label' => $this->abbreviateCurrency($value),
            ];
        })->values();

        $tickEvery = max(1, (int) ceil(count($periods) / 6));
        $xTicks = collect($periods)->values()->map(function (array $period, int $index) use (
            $paddingLeft,
            $plotWidth,
            $stepX,
            $pointCount,
            $tickEvery,
            $periods
        ) {
            $isLast = $index === count($periods) - 1;

            if (($index % $tickEvery !== 0) && ! $isLast) {
                return null;
            }

            $x = $pointCount === 1
                ? $paddingLeft + ($plotWidth / 2)
                : $paddingLeft + ($index * $stepX);

            return [
                'x' => round((float) $x, 2),
                'label' => $period['label'],
                'full_label' => $period['full_label'],
            ];
        })->filter()->values();

        return [
            'width' => $width,
            'height' => $height,
            'series' => $normalizedSeries,
            'y_ticks' => $yTicks,
            'x_ticks' => $xTicks,
            'max_label' => $this->abbreviateCurrency($chartMax),
        ];
    }

    protected function buildDonutChartPayload(
        array $items,
        int $categoryCount = 0,
        ?array $largestCategory = null,
        array $topCategories = []
    ): array
    {
        $size = 460;
        $center = $size / 2;
        $radius = 112;
        $strokeWidth = 34;
        $circumference = 2 * pi() * $radius;
        $palette = [
            '#1d4ed8',
            '#0f766e',
            '#f97316',
            '#e11d48',
            '#7c3aed',
            '#0ea5e9',
            '#14b8a6',
            '#f59e0b',
            '#475569',
            '#84cc16',
            '#ef4444',
            '#6366f1',
        ];

        $normalizedItems = collect($items)
            ->map(function (array $item) {
                return [
                    'label' => $item['label'] ?? 'Tanpa Kategori',
                    'value' => round((float) ($item['value'] ?? 0), 2),
                ];
            })
            ->filter(fn (array $item) => $item['value'] > 0)
            ->values();

        $total = round((float) $normalizedItems->sum('value'), 2);

        if ($total <= 0) {
            return [
                'has_data' => false,
                'size' => $size,
                'center' => $center,
                'radius' => $radius,
                'stroke_width' => $strokeWidth,
                'total' => 0,
                'total_label' => 'Rp 0',
                'category_count' => $categoryCount,
                'largest_category' => null,
                'top_categories' => [],
                'slices' => [],
            ];
        }

        $offset = 0.0;
        $accumulatedAngle = 0.0;
        $slices = $normalizedItems->values()->map(function (array $item, int $index) use (
            &$offset,
            &$accumulatedAngle,
            $palette,
            $circumference,
            $total,
            $center,
            $radius,
            $strokeWidth
        ) {
            $value = (float) $item['value'];
            $ratio = $value / $total;
            $dash = round($circumference * $ratio, 2);
            $sweepAngle = $ratio * 360;
            $midAngle = -90 + $accumulatedAngle + ($sweepAngle / 2);
            $midAngleRadians = deg2rad($midAngle);
            $lineStartRadius = $radius + ($strokeWidth / 2) + 8;
            $lineBreakRadius = $lineStartRadius + 20;
            $lineStartX = $center + (cos($midAngleRadians) * $lineStartRadius);
            $lineStartY = $center + (sin($midAngleRadians) * $lineStartRadius);
            $lineBreakX = $center + (cos($midAngleRadians) * $lineBreakRadius);
            $lineBreakY = $center + (sin($midAngleRadians) * $lineBreakRadius);
            $labelSide = cos($midAngleRadians) >= 0 ? 'right' : 'left';
            $slice = [
                'index' => $index,
                'label' => $item['label'],
                'label_short' => Str::limit($item['label'], 18, '...'),
                'value' => round($value, 2),
                'value_label' => 'Rp ' . number_format($value, 0, ',', '.'),
                'percentage' => round($ratio * 100, 2),
                'percentage_label' => number_format($ratio * 100, 2, ',', '.') . '%',
                'color' => $palette[$index % count($palette)],
                'dasharray' => $dash . ' ' . round(max($circumference - $dash, 0), 2),
                'dashoffset' => round(-$offset, 2),
                'label_side' => $labelSide,
                'label_anchor' => $labelSide === 'right' ? 'start' : 'end',
                'line_start_x' => round($lineStartX, 2),
                'line_start_y' => round($lineStartY, 2),
                'line_break_x' => round($lineBreakX, 2),
                'line_break_y' => round($lineBreakY, 2),
                'label_y' => round((float) $lineBreakY, 2),
            ];

            $offset += $dash;
            $accumulatedAngle += $sweepAngle;

            return $slice;
        })->values();

        $leftSlices = $this->normalizeDonutLabelPositions(
            $slices->where('label_side', 'left')->values()->all(),
            56,
            $size - 56,
            18,
            82,
            'left'
        );

        $rightSlices = $this->normalizeDonutLabelPositions(
            $slices->where('label_side', 'right')->values()->all(),
            56,
            $size - 56,
            18,
            $size - 82,
            'right'
        );

        $positionedSlices = collect(array_merge($leftSlices, $rightSlices))
            ->sortBy('index')
            ->values()
            ->all();

        return [
            'has_data' => true,
            'size' => $size,
            'center' => $center,
            'radius' => $radius,
            'stroke_width' => $strokeWidth,
            'total' => $total,
            'total_label' => 'Rp ' . number_format($total, 0, ',', '.'),
            'category_count' => max($categoryCount, count($slices)),
            'largest_category' => $largestCategory ? [
                'label' => $largestCategory['label'] ?? 'Tanpa Kategori',
                'value' => round((float) ($largestCategory['value'] ?? 0), 2),
                'value_label' => 'Rp ' . number_format((float) ($largestCategory['value'] ?? 0), 0, ',', '.'),
                'percentage' => round((float) ($largestCategory['percentage'] ?? 0), 2),
                'percentage_label' => number_format((float) ($largestCategory['percentage'] ?? 0), 2, ',', '.') . '%',
            ] : null,
            'top_categories' => collect($topCategories)->map(function (array $category, int $index) use ($palette) {
                $value = (float) ($category['value'] ?? 0);
                $percentage = (float) ($category['percentage'] ?? 0);

                return [
                    'rank' => $index + 1,
                    'label' => $category['label'] ?? 'Tanpa Kategori',
                    'value' => round($value, 2),
                    'value_label' => 'Rp ' . number_format($value, 0, ',', '.'),
                    'percentage' => round($percentage, 2),
                    'percentage_label' => number_format($percentage, 2, ',', '.') . '%',
                    'color' => $palette[$index % count($palette)],
                ];
            })->values()->all(),
            'slices' => $positionedSlices,
        ];
    }

    protected function normalizeDonutLabelPositions(
        array $slices,
        float $minY,
        float $maxY,
        float $gap,
        float $labelX,
        string $side
    ): array {
        if (empty($slices)) {
            return [];
        }

        $sorted = collect($slices)->sortBy('label_y')->values()->all();
        $currentY = $minY - $gap;

        foreach ($sorted as &$slice) {
            $slice['label_y'] = max((float) $slice['label_y'], $currentY + $gap);
            $currentY = (float) $slice['label_y'];
        }
        unset($slice);

        $overflow = (float) $sorted[count($sorted) - 1]['label_y'] - $maxY;

        if ($overflow > 0) {
            for ($index = count($sorted) - 1; $index >= 0; $index--) {
                $sorted[$index]['label_y'] = max($minY, (float) $sorted[$index]['label_y'] - $overflow);

                if ($index < count($sorted) - 1) {
                    $sorted[$index]['label_y'] = min(
                        (float) $sorted[$index]['label_y'],
                        (float) $sorted[$index + 1]['label_y'] - $gap
                    );
                }
            }
        }

        $currentY = $minY - $gap;

        foreach ($sorted as &$slice) {
            $slice['label_y'] = max((float) $slice['label_y'], $currentY + $gap);
            $slice['label_x'] = $labelX;
            $slice['label_anchor'] = $side === 'right' ? 'start' : 'end';
            $slice['line_end_x'] = $side === 'right' ? $labelX - 8 : $labelX + 8;
            $currentY = (float) $slice['label_y'];
        }
        unset($slice);

        return $sorted;
    }

    protected function chartCeiling(float $value): float
    {
        if ($value <= 0) {
            return 1;
        }

        $magnitude = pow(10, max(strlen((string) floor($value)) - 1, 0));
        $normalized = $value / $magnitude;
        $ceiling = ceil($normalized * 2) / 2;

        return $ceiling * $magnitude;
    }

    protected function buildSignalCardData(float $income, float $cost, string $type): array
    {
        $difference = round($income - $cost, 2);
        $ratio = $cost > 0 ? round(($income / $cost) * 100, 2) : null;
        $percent = $income > 0 ? round(($difference / $income) * 100, 2) : null;

        if ($difference >= 0 && (($ratio ?? 0) >= 120 || ($percent ?? 0) >= 30)) {
            $status = 'Sehat';
            $tone = 'emerald';
        } elseif ($difference >= 0) {
            $status = 'Terjaga';
            $tone = 'amber';
        } else {
            $status = 'Perlu Perhatian';
            $tone = 'rose';
        }

        return [
            'title' => $type === 'cash' ? 'Cash Health' : 'Profit Signal',
            'status' => $status,
            'tone' => $tone,
            'difference' => $difference,
            'ratio' => $ratio,
            'percent' => $percent,
            'description' => $type === 'cash'
                ? 'Membandingkan total cash in terhadap cash out pada periode ini.'
                : 'Membandingkan penjualan terhadap HPP untuk membaca margin kotor.',
            'meta' => $type === 'cash'
                ? 'Cash In vs Cash Out'
                : 'Penjualan vs HPP',
            'progress' => min(max((float) ($type === 'cash' ? ($ratio ?? 0) : ($percent !== null ? $percent + 100 : 0)), 0), 160),
        ];
    }

    protected function formatDateRangeLabel(Carbon $dateFrom, Carbon $dateTo): string
    {
        return sprintf(
            '%s %s %s - %s %s %s',
            $dateFrom->format('d'),
            $this->monthLabel((int) $dateFrom->month),
            $dateFrom->year,
            $dateTo->format('d'),
            $this->monthLabel((int) $dateTo->month),
            $dateTo->year
        );
    }

    protected function abbreviateCurrency(float $value): string
    {
        $absolute = abs($value);

        if ($absolute >= 1000000000) {
            return 'Rp ' . number_format($value / 1000000000, 1, ',', '.') . ' M';
        }

        if ($absolute >= 1000000) {
            return 'Rp ' . number_format($value / 1000000, 1, ',', '.') . ' Jt';
        }

        if ($absolute >= 1000) {
            return 'Rp ' . number_format($value / 1000, 0, ',', '.') . ' Rb';
        }

        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    protected function shortMonthLabel(int $month): string
    {
        return [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agu',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ][$month] ?? (string) $month;
    }

    // Utility parse range tanggal dari request, default: hari ini
    protected function parseDateRange(Request $request): array
    {
        $from = $request->input('date_from');
        $to   = $request->input('date_to');

        try {
            $dateFrom = $from ? Carbon::parse($from) : Carbon::today();
        } catch (\Exception $e) {
            $dateFrom = Carbon::today();
        }

        try {
            $dateTo = $to ? Carbon::parse($to) : Carbon::today();
        } catch (\Exception $e) {
            $dateTo = Carbon::today();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    protected function parseAnalysisDateRange(Request $request): array
    {
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        try {
            $dateFrom = $from ? Carbon::parse($from) : now()->startOfYear();
        } catch (\Exception $e) {
            $dateFrom = now()->startOfYear();
        }

        try {
            $dateTo = $to ? Carbon::parse($to) : now()->endOfMonth();
        } catch (\Exception $e) {
            $dateTo = now()->endOfMonth();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    protected function cashflowBaseQuery(Request $request): array
    {
        try {
            $dateFrom = $request->input('date_from')
                ? Carbon::parse($request->input('date_from'))->startOfDay()
                : now()->startOfMonth();
        } catch (\Exception $e) {
            $dateFrom = now()->startOfMonth();
        }

        try {
            $dateTo = $request->input('date_to')
                ? Carbon::parse($request->input('date_to'))->endOfDay()
                : now()->endOfMonth();
        } catch (\Exception $e) {
            $dateTo = now()->endOfMonth();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
        }

        $salesQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$dateFrom, $dateTo]);

        $cashInQuery = PurchaseOrder::query()
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at')
            ->whereBetween('cash_received_at', [$dateFrom, $dateTo]);

        $customerId = $request->input('customer_id');
        if ($customerId) {
            $salesQuery->where('customer_id', $customerId);
            $cashInQuery->where('customer_id', $customerId);
        }

        $expenseQuery = CashOut::query()
            ->whereBetween('expense_date', [
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
            ]);

        return [$salesQuery, $cashInQuery, $expenseQuery, $dateFrom, $dateTo, $customerId];
    }

    protected function monthLabel(int $month): string
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ][$month] ?? (string) $month;
    }
}
