<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryOpening;
use App\Models\InventoryPurchase;
use App\Models\ProfitLossAdjustment;
use App\Models\SalesActualItem;
use App\Models\SalesDailyClosing;
use App\Services\BalanceSheetService;
use App\Services\InventoryUsageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class FinalReportController extends Controller
{
    /**
     * Mapping label PDF Laporan Laba Rugi → kemungkinan nama
     * ExpenseCategory yang ada di database (case-insensitive).
     * Kategori direct-expense yang tidak match akan diappend di bawah
     * sebagai baris tambahan dengan nama aslinya.
     */
    private const PENGELUARAN_PDF_MAP = [
        'Keperluan Produksi' => ['keperluan produksi', 'operasional', 'gas'],
        'Lain-Lain' => ['lain-lain', 'lain lain'],
        'Salary' => ['sallary', 'salary', 'gaji'],
        'Pajak' => ['pajak'],
        'Transportasi' => ['transport', 'transportasi'],
        'Perawatan' => ['perawatan', 'maintenance'],
        'ATK' => ['atk'],
    ];

    public function index(
        Request $request,
        BalanceSheetService $balanceSheetService,
        InventoryUsageService $inventoryUsageService
    ): View {
        return view('accountingapp.reports.final', $this->buildReportData($request, $balanceSheetService, $inventoryUsageService));
    }

    public function exportExcel(
        Request $request,
        BalanceSheetService $balanceSheetService,
        InventoryUsageService $inventoryUsageService
    ) {
        $reportData = $this->buildReportData($request, $balanceSheetService, $inventoryUsageService);
        $fileName = 'laporan_final_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.reports.exports.final', $reportData),
            $fileName
        );
    }

    public function exportPdf(
        Request $request,
        BalanceSheetService $balanceSheetService,
        InventoryUsageService $inventoryUsageService
    ) {
        $reportData = $this->buildReportData($request, $balanceSheetService, $inventoryUsageService);
        $fileName = 'laporan_final_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.pdf';

        return Pdf::loadView('accountingapp.reports.exports.final', $reportData)
            ->setPaper('a4', 'portrait')
            ->download($fileName);
    }

    protected function buildReportData(
        Request $request,
        BalanceSheetService $balanceSheetService,
        InventoryUsageService $inventoryUsageService
    ): array {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $balanceSheet = $balanceSheetService->buildReport($dateTo);
        $balanceSummary = $this->summarizeBalanceSheet($balanceSheet);
        $profitLoss = $this->buildProfitLossData($dateFrom, $dateTo, $inventoryUsageService);

        return [
            'title' => 'Laporan Final',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'balanceSheet' => $balanceSheet,
            'balanceSummary' => $balanceSummary,
            'profitLoss' => $profitLoss,
        ];
    }

    /**
     * Reduce balance-sheet groups to single-line summaries (no breakdown):
     * Kas, Piutang Usaha, Aktiva Tetap, Persediaan, Kewajiban, Modal, Kekayaan.
     */
    protected function summarizeBalanceSheet(array $balanceSheet): array
    {
        $assetGroups = collect($balanceSheet['assetGroups'] ?? []);
        $liabilityGroups = collect($balanceSheet['liabilityGroups'] ?? []);
        $capitalRows = collect($balanceSheet['capitalRows'] ?? []);
        $wealthRows = collect($balanceSheet['wealthRows'] ?? []);

        $assetSummaries = $assetGroups->map(fn (array $group) => [
            'label' => $group['title'],
            'count' => collect($group['rows'] ?? [])->count(),
            'amount' => round((float) ($group['total'] ?? 0), 2),
        ])->values()->all();

        $liabilityRow = $liabilityGroups->first();
        $liabilitySummary = [
            'label' => $liabilityRow['title'] ?? 'Kewajiban',
            'count' => collect($liabilityRow['rows'] ?? [])->count(),
            'amount' => round((float) ($liabilityRow['total'] ?? 0), 2),
        ];

        $capitalSummary = [
            'label' => 'Modal',
            'count' => $capitalRows->count(),
            'amount' => round((float) $capitalRows->sum('amount'), 2),
        ];

        $wealthSummary = [
            'label' => 'Kekayaan',
            'count' => $wealthRows->count(),
            'amount' => round((float) $wealthRows->sum('amount'), 2),
        ];

        return [
            'assets' => $assetSummaries,
            'totalAssets' => round((float) ($balanceSheet['totalAssets'] ?? 0), 2),
            'liability' => $liabilitySummary,
            'capital' => $capitalSummary,
            'wealth' => $wealthSummary,
            'totalLiabilitiesAndEquity' => round((float) ($balanceSheet['totalLiabilitiesAndEquity'] ?? 0), 2),
        ];
    }

    protected function buildProfitLossData(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService
    ): array {
        $totalPenjualan = $this->calculateRevenue($dateFrom, $dateTo);
        $inventory = $this->calculateInventoryAggregate($dateFrom, $dateTo, $inventoryUsageService);
        $pengeluaranRows = $this->buildPengeluaranRows($dateFrom, $dateTo);

        $revenueAdjustmentTotal = $this->sumAdjustmentsByGroup($dateFrom, $dateTo, ProfitLossAdjustment::GROUP_REVENUE);
        $otherIncomeAdjustmentTotal = $this->sumAdjustmentsByGroup($dateFrom, $dateTo, ProfitLossAdjustment::GROUP_OTHER_INCOME);
        $cogsAdjustmentTotal = $this->sumAdjustmentsByGroup($dateFrom, $dateTo, ProfitLossAdjustment::GROUP_COGS);

        $totalPenjualan = round($totalPenjualan + $revenueAdjustmentTotal + $otherIncomeAdjustmentTotal, 2);

        $bahanBakuBaru = round((float) ($inventory['baru'] ?? 0) + $cogsAdjustmentTotal, 2);
        $bahanBakuTerpakai = round((float) ($inventory['terpakai'] ?? 0) + $cogsAdjustmentTotal, 2);

        $totalPengeluaran = round((float) collect($pengeluaranRows)->sum('amount'), 2);

        $labaRugi = round($totalPenjualan - $bahanBakuTerpakai - $totalPengeluaran, 2);
        $totalCheck = round($bahanBakuTerpakai + $totalPengeluaran + $labaRugi, 2);

        return [
            'totalPenjualan' => $totalPenjualan,
            'bahanBakuLama' => round((float) ($inventory['lama'] ?? 0), 2),
            'bahanBakuBaru' => $bahanBakuBaru,
            'sisaStok' => round((float) ($inventory['sisa'] ?? 0), 2),
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

    protected function loadAdjustmentRows(Carbon $dateFrom, Carbon $dateTo, string $group): Collection
    {
        return ProfitLossAdjustment::query()
            ->where('statement_group', $group)
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get(['adjustment_date', 'label', 'amount'])
            ->map(fn (ProfitLossAdjustment $row) => [
                'label' => $row->label,
                'amount' => round((float) $row->amount, 2),
            ])
            ->values()
            ->toBase();
    }

    protected function calculateRevenue(Carbon $dateFrom, Carbon $dateTo): float
    {
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

        return round($salesActualRevenue - $salesDiscount, 2);
    }

    /**
     * Aggregate Bahan Baku Lama / Baru / Sisa Stok / Terpakai across
     * all stock-category inventory items in the period.
     */
    protected function calculateInventoryAggregate(
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

    /**
     * Build PENGELUARAN rows: for each PDF label, sum CashOut totals from
     * matching ExpenseCategory names. Categories without a match are
     * appended below using their original DB name. Categories that go to
     * HPP (include_hpp=true) and any "Adjustment" category are skipped.
     */
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
            ->reject(fn (CashOut $expense) => $this->isAdjustmentExpenseCategory($expense))
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

    protected function isAdjustmentExpenseCategory(CashOut $expense): bool
    {
        return strcasecmp(trim((string) ($expense->category?->name ?? '')), 'Adjustment') === 0;
    }

    protected function mergeOperatingExpenseAdjustments(Collection $expenseAggregates, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $adjustmentsByCategoryName = ProfitLossAdjustment::query()
            ->where('statement_group', ProfitLossAdjustment::GROUP_OPERATING_EXPENSE)
            ->whereNotNull('expense_category_id')
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->with('expenseCategory:id,name')
            ->get(['expense_category_id', 'amount', 'expense_category_id as eid'])
            ->groupBy(fn (ProfitLossAdjustment $row) => $row->expenseCategory?->name ?: 'Tanpa Kategori')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        foreach ($adjustmentsByCategoryName as $categoryName => $amount) {
            $current = (float) ($expenseAggregates[$categoryName] ?? 0);
            $expenseAggregates[$categoryName] = round($current + $amount, 2);
        }

        return $expenseAggregates->filter(fn ($amount) => abs((float) $amount) >= 0.005);
    }

    protected function parseDateRange(Request $request): array
    {
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        try {
            $dateFrom = $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth();
        } catch (\Throwable) {
            $dateFrom = now()->startOfMonth();
        }

        try {
            $dateTo = $to ? Carbon::parse($to)->endOfDay() : now()->endOfMonth();
        } catch (\Throwable) {
            $dateTo = now()->endOfMonth();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }
}
