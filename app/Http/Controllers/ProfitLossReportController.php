<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Http\Controllers\Concerns\ChecksPeriodClosing;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\OtherIncome;
use App\Models\ProfitLossAdjustment;
use App\Models\SalesActual;
use App\Models\SalesDailyClosing;
use App\Models\SalesActualItem;
use App\Models\StockOpname;
use App\Services\InventoryUsageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class ProfitLossReportController extends Controller
{
    use ChecksPeriodClosing;

    private const PAYABLE_SETTLEMENT_CATEGORY_NAME = 'Pembayaran Hutang';
    private const SALES_ACTUAL_INCOME_CATEGORY_NAME = 'Sales Actual';

    private const PENGELUARAN_PDF_MAP = [
        'Keperluan Produksi' => ['keperluan produksi', 'operasional', 'gas'],
        'Lain-Lain' => ['lain-lain', 'lain lain'],
        'Salary' => ['sallary', 'salary', 'gaji'],
        'Pajak' => ['pajak'],
        'Transportasi' => ['transport', 'transportasi'],
        'Perawatan' => ['perawatan', 'maintenance'],
        'ATK' => ['atk'],
    ];

    public function index(Request $request, InventoryUsageService $inventoryUsageService): View
    {
        return view('accountingapp.reports.profit-loss', $this->prepareReportData($request, $inventoryUsageService, 'monthly'));
    }

    public function yearly(Request $request, InventoryUsageService $inventoryUsageService): View
    {
        return view('accountingapp.reports.profit-loss', $this->prepareReportData($request, $inventoryUsageService, 'yearly'));
    }

    public function exportExcel(Request $request, InventoryUsageService $inventoryUsageService)
    {
        $reportData = $this->prepareReportData($request, $inventoryUsageService, 'monthly');
        $fileName = 'laporan_laba_rugi_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.reports.exports.profit-loss', $reportData),
            $fileName
        );
    }

    public function exportPdf(Request $request, InventoryUsageService $inventoryUsageService)
    {
        $reportData = $this->prepareReportData($request, $inventoryUsageService, 'monthly');
        $fileName = 'laporan_laba_rugi_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.pdf';

        return Pdf::loadView('accountingapp.reports.exports.profit-loss', $reportData)
            ->setPaper('a4', 'portrait')
            ->download($fileName);
    }

    public function exportYearlyExcel(Request $request, InventoryUsageService $inventoryUsageService)
    {
        $reportData = $this->prepareReportData($request, $inventoryUsageService, 'yearly');
        $fileName = 'laporan_laba_rugi_tahunan_' . $reportData['selectedYear'] . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.reports.exports.profit-loss', $reportData),
            $fileName
        );
    }

    public function exportYearlyPdf(Request $request, InventoryUsageService $inventoryUsageService)
    {
        $reportData = $this->prepareReportData($request, $inventoryUsageService, 'yearly');
        $fileName = 'laporan_laba_rugi_tahunan_' . $reportData['selectedYear'] . '.pdf';

        return Pdf::loadView('accountingapp.reports.exports.profit-loss', $reportData)
            ->setPaper('a4', 'landscape')
            ->download($fileName);
    }

    protected function prepareReportData(
        Request $request,
        InventoryUsageService $inventoryUsageService,
        string $periodType
    ): array {
        $selectedYear = (int) $request->input('year', now()->year);

        if ($periodType === 'yearly') {
            $dateFrom = Carbon::create($selectedYear, 1, 1)->startOfMonth();
            $dateTo = Carbon::create($selectedYear, 12, 31)->endOfMonth();
        } else {
            [$dateFrom, $dateTo] = $this->parseDateRange($request);
            $selectedYear = (int) $dateTo->year;
        }

        $rangePeriodStatus = $this->periodStatusForRange($dateFrom, $dateTo);
        $statement = $this->buildStatementData($dateFrom, $dateTo, $inventoryUsageService, true);
        $profitLoss = $periodType === 'monthly'
            ? $this->buildFinalStyleProfitLoss($dateFrom, $dateTo, $inventoryUsageService)
            : null;
        $availableYears = collect(range(now()->year - 4, now()->year + 1))
            ->push($selectedYear)
            ->unique()
            ->sortDesc()
            ->values();

        $yearlyMatrix = $periodType === 'yearly'
            ? $this->buildYearlyMatrix($selectedYear, $inventoryUsageService)
            : null;

        return [
            'title' => 'Laporan Laba Rugi',
            'pageLayout' => $periodType === 'yearly' ? 'layouts.accounting-report' : 'layouts.accountingapp',
            'periodType' => $periodType,
            'selectedYear' => $selectedYear,
            'availableYears' => $availableYears,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'rangePeriodStatus' => $rangePeriodStatus,
            'statement' => $statement,
            'profitLoss' => $profitLoss,
            'yearlyMatrix' => $yearlyMatrix,
        ];
    }

    /**
     * Laba Rugi versi "FinalStyle" untuk rentang tanggal — ini figur "Laba" yang
     * ditampilkan di laporan Laba Rugi bulanan. Dipakai ulang oleh Owner App > Analisa HPP
     * sebagai "Profit Real".
     */
    public function finalStyleProfitLossForRange(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService
    ): array {
        return $this->buildFinalStyleProfitLoss($dateFrom, $dateTo, $inventoryUsageService);
    }

    protected function buildFinalStyleProfitLoss(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService
    ): array {
        $salesActualRevenue = (float) SalesActualItem::query()
            ->whereHas('salesActual', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'submitted')
                    ->whereBetween('submitted_at', [
                        $dateFrom->copy()->startOfDay(),
                        $dateTo->copy()->endOfDay(),
                    ]);
            })
            ->sum('subtotal_actual');

        $salesDiscount = (float) SalesDailyClosing::query()
            ->whereDate('closing_date', '>=', $dateFrom->toDateString())
            ->whereDate('closing_date', '<=', $dateTo->toDateString())
            ->sum('discount_amount');

        $revenueAdjustmentTotal = $this->sumAdjustmentsByGroup($dateFrom, $dateTo, ProfitLossAdjustment::GROUP_REVENUE);
        $otherIncomeAdjustmentTotal = $this->sumAdjustmentsByGroup($dateFrom, $dateTo, ProfitLossAdjustment::GROUP_OTHER_INCOME);
        $cogsAdjustmentTotal = $this->sumAdjustmentsByGroup($dateFrom, $dateTo, ProfitLossAdjustment::GROUP_COGS);

        $totalPenjualan = round($salesActualRevenue - $salesDiscount + $revenueAdjustmentTotal + $otherIncomeAdjustmentTotal, 2);

        $inventory = $this->aggregateInventory($dateFrom, $dateTo, $inventoryUsageService);
        $bahanBakuLama = round((float) $inventory['lama'], 2);
        $bahanBakuBaru = round((float) $inventory['baru'] + $cogsAdjustmentTotal, 2);
        $sisaStok = round((float) $inventory['sisa'], 2);
        $bahanBakuTerpakai = round((float) $inventory['terpakai'] + $cogsAdjustmentTotal, 2);

        $pengeluaranRows = $this->buildPengeluaranRows($dateFrom, $dateTo);
        $totalPengeluaran = round((float) collect($pengeluaranRows)->sum('amount'), 2);

        $labaRugi = round($totalPenjualan - $bahanBakuTerpakai - $totalPengeluaran, 2);
        $totalCheck = round($bahanBakuTerpakai + $totalPengeluaran + $labaRugi, 2);

        return [
            'totalPenjualan' => $totalPenjualan,
            'bahanBakuLama' => $bahanBakuLama,
            'bahanBakuBaru' => $bahanBakuBaru,
            'sisaStok' => $sisaStok,
            'bahanBakuTerpakai' => $bahanBakuTerpakai,
            'pengeluaranRows' => $pengeluaranRows,
            'totalPengeluaran' => $totalPengeluaran,
            'labaRugi' => $labaRugi,
            'total' => $totalCheck,
        ];
    }

    protected function sumAdjustmentsByGroup(Carbon $dateFrom, Carbon $dateTo, string $group): float
    {
        return round((float) ProfitLossAdjustment::query()
            ->where('statement_group', $group)
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->sum('amount'), 2);
    }

    protected function aggregateInventory(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService
    ): array {
        $items = InventoryItem::query()
            ->whereIn('category', InventoryItem::stockCategories())
            ->get(['id']);

        $totals = ['lama' => 0.0, 'baru' => 0.0, 'sisa' => 0.0, 'terpakai' => 0.0];

        foreach ($items as $item) {
            $summary = $inventoryUsageService->calculateForItem($item->id, $dateFrom, $dateTo);
            $totals['lama'] += (float) ($summary['opening'] ?? 0);
            $totals['baru'] += (float) ($summary['purchases'] ?? 0);
            $totals['sisa'] += (float) ($summary['ending'] ?? 0);
            $totals['terpakai'] += (float) ($summary['usage'] ?? 0);
        }

        return $totals;
    }

    protected function buildPengeluaranRows(Carbon $dateFrom, Carbon $dateTo): array
    {
        $expenseAggregates = CashOut::query()
            ->with('category:id,name,expense_mode,include_hpp')
            ->whereDate('expense_date', '>=', $dateFrom->toDateString())
            ->whereDate('expense_date', '<=', $dateTo->toDateString())
            ->whereHas('category', function ($query) {
                $query->where('expense_mode', ExpenseCategory::MODE_DIRECT_EXPENSE)
                    ->where('include_hpp', false);
            })
            ->get(['expense_category_id', 'amount'])
            ->reject(fn (CashOut $expense) => strcasecmp(trim((string) ($expense->category?->name ?? '')), 'Adjustment') === 0)
            ->groupBy(fn (CashOut $expense) => $expense->category?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        $expenseAggregates = $this->mergeOperatingExpenseAdjustments($expenseAggregates, $dateFrom, $dateTo);

        $usedCategoryNames = collect();
        $rows = [];

        foreach (self::PENGELUARAN_PDF_MAP as $label => $synonyms) {
            $synonymSet = collect($synonyms)->map(fn ($name) => mb_strtolower(trim($name)));
            $matchTotal = 0.0;

            foreach ($expenseAggregates as $categoryName => $amount) {
                if ($synonymSet->contains(mb_strtolower(trim($categoryName)))) {
                    $matchTotal += (float) $amount;
                    $usedCategoryNames->push($categoryName);
                }
            }

            $rows[] = [
                'label' => $label,
                'amount' => round($matchTotal, 2),
                'is_extra' => false,
            ];
        }

        $usedSet = $usedCategoryNames->map(fn ($name) => mb_strtolower(trim($name)))->unique();

        foreach ($expenseAggregates as $categoryName => $amount) {
            if ($usedSet->contains(mb_strtolower(trim($categoryName)))) {
                continue;
            }

            $rows[] = [
                'label' => $categoryName,
                'amount' => round((float) $amount, 2),
                'is_extra' => true,
            ];
        }

        return $rows;
    }

    protected function mergeOperatingExpenseAdjustments(Collection $expenseAggregates, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $adjustmentsByCategoryName = ProfitLossAdjustment::query()
            ->where('statement_group', ProfitLossAdjustment::GROUP_OPERATING_EXPENSE)
            ->whereNotNull('expense_category_id')
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->with('expenseCategory:id,name')
            ->get(['expense_category_id', 'amount'])
            ->groupBy(fn (ProfitLossAdjustment $row) => $row->expenseCategory?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        foreach ($adjustmentsByCategoryName as $categoryName => $amount) {
            $current = (float) ($expenseAggregates[$categoryName] ?? 0);
            $expenseAggregates[$categoryName] = round($current + $amount, 2);
        }

        return $expenseAggregates->filter(fn ($amount) => abs((float) $amount) >= 0.005);
    }

    /**
     * Statement Laba Rugi untuk rentang tanggal (dipakai ulang oleh Owner App > Analisa HPP
     * agar "Profit Real" konsisten dengan laporan Laba Rugi).
     */
    public function statementForRange(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService,
        bool $includeWarnings = false
    ): array {
        return $this->buildStatementData($dateFrom, $dateTo, $inventoryUsageService, $includeWarnings);
    }

    protected function buildStatementData(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService,
        bool $includeWarnings = true
    ): array {
        $salesActualRevenueTotal = round((float) SalesActualItem::query()
            ->whereHas('salesActual', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'submitted')
                    ->whereBetween('submitted_at', [
                        $dateFrom->copy()->startOfDay(),
                        $dateTo->copy()->endOfDay(),
                    ]);
            })
            ->sum('subtotal_actual'), 2);

        $salesActualCount = SalesActual::query()
            ->where('status', 'submitted')
            ->whereBetween('submitted_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->count();

        $salesDiscountTotal = round((float) SalesDailyClosing::query()
            ->whereDate('closing_date', '>=', $dateFrom->toDateString())
            ->whereDate('closing_date', '<=', $dateTo->toDateString())
            ->sum('discount_amount'), 2);

        $incomeRows = OtherIncome::query()
            ->with('category:id,name')
            ->whereDate('income_date', '>=', $dateFrom->toDateString())
            ->whereDate('income_date', '<=', $dateTo->toDateString())
            ->get(['income_category_id', 'source_type', 'source_id', 'amount', 'description'])
            ->reject(fn (OtherIncome $income) => $income->isGeneratedBySalesActual() || $this->isAdjustmentIncomeCategory($income))
            ->groupBy(fn (OtherIncome $income) => $income->category?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows, string $label) => [
                'label' => $label,
                'amount' => round((float) $rows->sum('amount'), 2),
            ]);

        $revenueAdjustmentRows = $this->buildProfitLossAdjustmentRows(
            $dateFrom,
            $dateTo,
            ProfitLossAdjustment::GROUP_REVENUE
        );
        $cogsAdjustmentRows = $this->buildProfitLossAdjustmentRows(
            $dateFrom,
            $dateTo,
            ProfitLossAdjustment::GROUP_COGS
        );
        [$operatingExpenseCategoryAdjustments, $operatingExpenseAdjustmentRows] = $this->buildOperatingExpenseAdjustments(
            $dateFrom,
            $dateTo
        );
        $otherIncomeAdjustmentRows = $this->buildProfitLossAdjustmentRows(
            $dateFrom,
            $dateTo,
            ProfitLossAdjustment::GROUP_OTHER_INCOME
        );

        $salesActualRevenueRows = $salesActualRevenueTotal > 0
            ? collect([[
                'label' => self::SALES_ACTUAL_INCOME_CATEGORY_NAME,
                'amount' => $salesActualRevenueTotal,
            ]])
            : collect();

        if ($salesDiscountTotal > 0) {
            $salesActualRevenueRows->push([
                'label' => 'Diskon Penjualan',
                'amount' => -1 * $salesDiscountTotal,
                'meta' => 'Total diskon dari closing penjualan harian.',
            ]);
        }

        $revenueRows = $salesActualRevenueRows
            ->merge($revenueAdjustmentRows)
            ->values();
        $salesRevenue = round((float) $revenueRows->sum('amount'), 2);

        $otherIncomeRows = $incomeRows
            ->sortByDesc('amount')
            ->values()
            ->toBase()
            ->merge($otherIncomeAdjustmentRows)
            ->values();

        $otherIncomeTotal = round((float) $otherIncomeRows->sum('amount'), 2);

        $operatingExpenseRows = CashOut::query()
            ->with('category:id,name,expense_mode')
            ->whereDate('expense_date', '>=', $dateFrom->toDateString())
            ->whereDate('expense_date', '<=', $dateTo->toDateString())
            ->whereHas('category', function ($query) {
                $query->where('expense_mode', ExpenseCategory::MODE_DIRECT_EXPENSE)
                    ->where('name', '!=', self::PAYABLE_SETTLEMENT_CATEGORY_NAME);
            })
            ->get(['expense_category_id', 'amount'])
            ->reject(fn (CashOut $expense) => $this->isAdjustmentExpenseCategory($expense))
            ->groupBy(fn (CashOut $expense) => $expense->category?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows, string $label) => [
                'label' => $label,
                'amount' => round((float) $rows->sum('amount'), 2),
            ]);

        foreach ($operatingExpenseCategoryAdjustments as $categoryName => $amount) {
            if ($operatingExpenseRows->has($categoryName)) {
                $existing = $operatingExpenseRows->get($categoryName);
                $operatingExpenseRows->put($categoryName, [
                    'label' => $existing['label'],
                    'amount' => round((float) $existing['amount'] + (float) $amount, 2),
                ]);
            } else {
                $operatingExpenseRows->put($categoryName, [
                    'label' => $categoryName,
                    'amount' => round((float) $amount, 2),
                ]);
            }
        }

        $operatingExpenseRows = $operatingExpenseRows
            ->filter(fn ($row) => abs((float) $row['amount']) >= 0.005)
            ->sortByDesc('amount')
            ->values()
            ->toBase()
            ->merge($operatingExpenseAdjustmentRows)
            ->values();

        $operatingExpenseTotal = round((float) $operatingExpenseRows->sum('amount'), 2);

        [$inventoryRows, $inventoryWarnings] = $this->buildInventoryUsageRows(
            $dateFrom,
            $dateTo,
            $inventoryUsageService,
            $includeWarnings
        );

        $cogsTotal = round((float) $inventoryRows->sum('usage') + (float) $cogsAdjustmentRows->sum('amount'), 2);
        $grossProfit = round($salesRevenue - $cogsTotal, 2);
        $operatingProfit = round($grossProfit - $operatingExpenseTotal, 2);
        $netProfit = round($operatingProfit + $otherIncomeTotal, 2);
        $netProfitPercentage = abs($salesRevenue) >= 0.005
            ? round(($netProfit / $salesRevenue) * 100, 2)
            : null;

        return [
            'salesRevenue' => $salesRevenue,
            'salesActualRevenueTotal' => $salesActualRevenueTotal,
            'salesDiscountTotal' => $salesDiscountTotal,
            'revenueRows' => $revenueRows,
            'salesActualCount' => $salesActualCount,
            'otherIncomeRows' => $otherIncomeRows,
            'otherIncomeTotal' => $otherIncomeTotal,
            'operatingExpenseRows' => $operatingExpenseRows,
            'operatingExpenseTotal' => $operatingExpenseTotal,
            'inventoryRows' => $inventoryRows,
            'cogsAdjustmentRows' => $cogsAdjustmentRows,
            'inventoryWarnings' => $inventoryWarnings,
            'cogsTotal' => $cogsTotal,
            'grossProfit' => $grossProfit,
            'operatingProfit' => $operatingProfit,
            'netProfit' => $netProfit,
            'netProfitPercentage' => $netProfitPercentage,
        ];
    }

    protected function isAdjustmentIncomeCategory(OtherIncome $income): bool
    {
        return strcasecmp(trim((string) ($income->category?->name ?? '')), 'Adjustment') === 0;
    }

    protected function isAdjustmentExpenseCategory(CashOut $expense): bool
    {
        return strcasecmp(trim((string) ($expense->category?->name ?? '')), 'Adjustment') === 0;
    }

    /**
     * Split operating-expense adjustments into:
     *   [0] adjustments WITH expense_category_id → keyed by category name (merge into category rows)
     *   [1] adjustments WITHOUT expense_category_id → collection of {label, amount, meta} (separate rows)
     */
    protected function buildOperatingExpenseAdjustments(Carbon $dateFrom, Carbon $dateTo): array
    {
        $adjustments = ProfitLossAdjustment::query()
            ->where('statement_group', ProfitLossAdjustment::GROUP_OPERATING_EXPENSE)
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->with('expenseCategory:id,name')
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get();

        $categoryAdjustments = $adjustments
            ->filter(fn (ProfitLossAdjustment $row) => $row->expense_category_id !== null)
            ->groupBy(fn (ProfitLossAdjustment $row) => $row->expenseCategory?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        $standaloneRows = $adjustments
            ->filter(fn (ProfitLossAdjustment $row) => $row->expense_category_id === null)
            ->map(fn (ProfitLossAdjustment $row) => [
                'label' => $row->label,
                'amount' => round((float) $row->amount, 2),
                'meta' => collect([
                    'Adjustment laba rugi per ' . optional($row->adjustment_date)->format('d-m-Y'),
                    $row->notes,
                ])->filter()->implode(' | '),
            ])
            ->values()
            ->toBase();

        return [$categoryAdjustments, $standaloneRows];
    }

    protected function buildProfitLossAdjustmentRows(Carbon $dateFrom, Carbon $dateTo, string $group): Collection
    {
        return ProfitLossAdjustment::query()
            ->where('statement_group', $group)
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get(['adjustment_date', 'label', 'amount', 'notes'])
            ->map(function (ProfitLossAdjustment $adjustment) {
                return [
                    'label' => $adjustment->label,
                    'amount' => round((float) $adjustment->amount, 2),
                    'meta' => collect([
                        'Adjustment laba rugi per ' . optional($adjustment->adjustment_date)->format('d-m-Y'),
                        $adjustment->notes,
                    ])->filter()->implode(' | '),
                ];
            })
            ->values();
    }

    protected function buildYearlyMatrix(int $year, InventoryUsageService $inventoryUsageService): array
    {
        $now = now();
        $cutoffMonth = match (true) {
            $year < (int) $now->year => 12,
            $year === (int) $now->year => min((int) $now->month + 1, 12),
            default => 0,
        };

        $months = collect(range(1, 12))->map(function (int $month) use ($year, $inventoryUsageService, $cutoffMonth) {
            $dateFrom = Carbon::create($year, $month, 1)->startOfMonth();
            $dateTo = $dateFrom->copy()->endOfMonth();
            $isPlaceholder = $month > $cutoffMonth;

            return [
                'key' => $dateFrom->format('Y-m'),
                'label' => $this->monthLabel($month) . ' ' . $year,
                'is_placeholder' => $isPlaceholder,
                'statement' => $isPlaceholder
                    ? $this->emptyStatementData()
                    : $this->buildStatementData($dateFrom, $dateTo, $inventoryUsageService, false),
            ];
        });

        $cogsLabels = $months
            ->flatMap(function (array $month) {
                return $month['statement']['inventoryRows']
                    ->pluck('item_name')
                    ->merge($month['statement']['cogsAdjustmentRows']->pluck('label'));
            })
            ->unique()
            ->values();

        $revenueLabels = $months
            ->flatMap(fn (array $month) => $month['statement']['revenueRows']->pluck('label'))
            ->unique()
            ->values();

        $expenseLabels = $months
            ->flatMap(fn (array $month) => $month['statement']['operatingExpenseRows']->pluck('label'))
            ->unique()
            ->values();

        $otherIncomeLabels = $months
            ->flatMap(fn (array $month) => $month['statement']['otherIncomeRows']->pluck('label'))
            ->unique()
            ->values();

        return [
            'months' => $months,
            'groups' => [
                [
                    'title' => 'Pendapatan',
                    'rows' => $revenueLabels->map(function (string $label) use ($months) {
                        return [
                            'label' => $label,
                            'values' => $months->mapWithKeys(function (array $month) use ($label) {
                                $row = $month['statement']['revenueRows']->firstWhere('label', $label);

                                return [$month['key'] => $row ? (float) $row['amount'] : 0];
                            })->all(),
                        ];
                    }),
                    'total_label' => 'Total Pendapatan',
                    'total_values' => $months->mapWithKeys(fn (array $month) => [
                        $month['key'] => (float) $month['statement']['salesRevenue'],
                    ])->all(),
                ],
                [
                    'title' => 'Beban Pokok Pendapatan',
                    'rows' => $cogsLabels->map(function (string $label) use ($months) {
                        return [
                            'label' => $label,
                            'values' => $months->mapWithKeys(function (array $month) use ($label) {
                                $inventoryRow = $month['statement']['inventoryRows']->firstWhere('item_name', $label);
                                $adjustmentRow = $month['statement']['cogsAdjustmentRows']->firstWhere('label', $label);

                                return [$month['key'] => $inventoryRow
                                    ? -1 * (float) $inventoryRow['usage']
                                    : ($adjustmentRow ? -1 * (float) $adjustmentRow['amount'] : 0)];
                            })->all(),
                        ];
                    }),
                    'empty_label' => 'Belum ada pemakaian inventory pada tahun ini',
                    'total_label' => 'Total Beban Pokok Pendapatan',
                    'total_values' => $months->mapWithKeys(fn (array $month) => [
                        $month['key'] => -1 * (float) $month['statement']['cogsTotal'],
                    ])->all(),
                    'summary_label' => 'Laba Kotor',
                    'summary_values' => $months->mapWithKeys(fn (array $month) => [
                        $month['key'] => (float) $month['statement']['grossProfit'],
                    ])->all(),
                ],
                [
                    'title' => 'Beban Operasional',
                    'rows' => $expenseLabels->map(function (string $label) use ($months) {
                        return [
                            'label' => $label,
                            'values' => $months->mapWithKeys(function (array $month) use ($label) {
                                $row = $month['statement']['operatingExpenseRows']->firstWhere('label', $label);

                                return [$month['key'] => $row ? -1 * (float) $row['amount'] : 0];
                            })->all(),
                        ];
                    }),
                    'empty_label' => 'Belum ada beban operasional pada tahun ini',
                    'total_label' => 'Total Beban Operasional',
                    'total_values' => $months->mapWithKeys(fn (array $month) => [
                        $month['key'] => -1 * (float) $month['statement']['operatingExpenseTotal'],
                    ])->all(),
                    'summary_label' => 'Laba Operasional',
                    'summary_values' => $months->mapWithKeys(fn (array $month) => [
                        $month['key'] => (float) $month['statement']['operatingProfit'],
                    ])->all(),
                ],
                [
                    'title' => 'Pendapatan Lain-lain',
                    'rows' => $otherIncomeLabels->map(function (string $label) use ($months) {
                        return [
                            'label' => $label,
                            'values' => $months->mapWithKeys(function (array $month) use ($label) {
                                $row = $month['statement']['otherIncomeRows']->firstWhere('label', $label);

                                return [$month['key'] => $row ? (float) $row['amount'] : 0];
                            })->all(),
                        ];
                    }),
                    'empty_label' => 'Belum ada pendapatan lain-lain pada tahun ini',
                    'total_label' => 'Total Pendapatan Lain-lain',
                    'total_values' => $months->mapWithKeys(fn (array $month) => [
                        $month['key'] => (float) $month['statement']['otherIncomeTotal'],
                    ])->all(),
                ],
            ],
            'net_profit_values' => $months->mapWithKeys(fn (array $month) => [
                $month['key'] => (float) $month['statement']['netProfit'],
            ])->all(),
        ];
    }

    protected function emptyStatementData(): array
    {
        return [
            'salesRevenue' => 0.0,
            'salesActualRevenueTotal' => 0.0,
            'salesDiscountTotal' => 0.0,
            'revenueRows' => collect(),
            'salesActualCount' => 0,
            'otherIncomeRows' => collect(),
            'otherIncomeTotal' => 0.0,
            'operatingExpenseRows' => collect(),
            'operatingExpenseTotal' => 0.0,
            'inventoryRows' => collect(),
            'cogsAdjustmentRows' => collect(),
            'inventoryWarnings' => collect(),
            'cogsTotal' => 0.0,
            'grossProfit' => 0.0,
            'operatingProfit' => 0.0,
            'netProfit' => 0.0,
            'netProfitPercentage' => null,
        ];
    }

    protected function buildInventoryUsageRows(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService,
        bool $includeWarnings = true
    ): array {
        $latestOpnameByItem = StockOpname::query()
            ->selectRaw('inventory_item_id, MAX(opname_date) as latest_opname_date')
            ->whereDate('opname_date', '<=', $dateTo->toDateString())
            ->groupBy('inventory_item_id')
            ->pluck('latest_opname_date', 'inventory_item_id');

        $warnings = collect();

        $rows = InventoryItem::query()
            ->whereIn('category', InventoryItem::stockCategories())
            ->orderBy('name')
            ->get(['id', 'name', 'unit'])
            ->map(function (InventoryItem $item) use (
                $dateFrom,
                $dateTo,
                $inventoryUsageService,
                $latestOpnameByItem,
                $warnings,
                $includeWarnings
            ) {
                $summary = $inventoryUsageService->calculateForItem($item->id, $dateFrom, $dateTo);
                $opening = round((float) ($summary['opening'] ?? 0), 2);
                $purchases = round((float) ($summary['purchases'] ?? 0), 2);
                $ending = round((float) ($summary['ending'] ?? 0), 2);
                $usage = round((float) ($summary['usage'] ?? 0), 2);

                if (abs($opening) < 0.005 && abs($purchases) < 0.005 && abs($ending) < 0.005 && abs($usage) < 0.005) {
                    return null;
                }

                if ($includeWarnings && ($opening > 0 || $purchases > 0) && ! $latestOpnameByItem->has($item->id)) {
                    $warnings->push(sprintf(
                        'Item %s belum memiliki stock opname s/d %s, sehingga HPP bisa belum akurat.',
                        $item->name,
                        $dateTo->format('d-m-Y')
                    ));
                }

                return [
                    'item_name' => $item->name,
                    'unit' => $item->unit,
                    'opening' => $opening,
                    'purchases' => $purchases,
                    'ending' => $ending,
                    'usage' => $usage,
                    'latest_opname_date' => $latestOpnameByItem->get($item->id),
                ];
            })
            ->filter()
            ->sortByDesc('usage')
            ->values()
            ->toBase();

        return [$rows, $warnings->unique()->values()];
    }

    protected function parseDateRange(Request $request): array
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
}
