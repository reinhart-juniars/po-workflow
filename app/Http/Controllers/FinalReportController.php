<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Services\BalanceSheetService;
use App\Services\InventoryUsageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class FinalReportController extends Controller
{
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
        $fileName = 'laporan_final_'.$reportData['dateFrom']->format('Ymd').'_'.$reportData['dateTo']->format('Ymd').'.xlsx';

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
        $fileName = 'laporan_final_'.$reportData['dateFrom']->format('Ymd').'_'.$reportData['dateTo']->format('Ymd').'.pdf';

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

    /**
     * Laba Rugi Laporan Final = Laba Rugi bulanan (versi "FinalStyle"), satu sumber
     * supaya kedua laporan dan Analisa HPP tidak bisa berbeda angka.
     */
    protected function buildProfitLossData(
        Carbon $dateFrom,
        Carbon $dateTo,
        InventoryUsageService $inventoryUsageService
    ): array {
        return app(ProfitLossReportController::class)
            ->finalStyleProfitLossForRange($dateFrom, $dateTo, $inventoryUsageService);
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
