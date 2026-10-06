<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Services\BalanceSheetService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class BalanceSheetReportController extends Controller
{
    public function index(Request $request, BalanceSheetService $balanceSheetService): View
    {
        return view('accountingapp.reports.balance-sheet', $this->buildReportData($request, $balanceSheetService));
    }

    public function exportExcel(Request $request, BalanceSheetService $balanceSheetService)
    {
        $reportData = $this->buildReportData($request, $balanceSheetService) + ['sideLayout' => false];
        $fileName = 'laporan_neraca_' . $reportData['reportDate']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.reports.exports.balance-sheet', $reportData),
            $fileName
        );
    }

    public function exportPdf(Request $request, BalanceSheetService $balanceSheetService)
    {
        $reportData = $this->buildReportData($request, $balanceSheetService) + ['sideLayout' => true];
        $fileName = 'laporan_neraca_' . $reportData['reportDate']->format('Ymd') . '.pdf';

        return Pdf::loadView('accountingapp.reports.exports.balance-sheet', $reportData)
            ->setPaper('a4', 'landscape')
            ->download($fileName);
    }

    protected function buildReportData(Request $request, BalanceSheetService $balanceSheetService): array
    {
        $reportDate = $request->filled('report_date')
            ? Carbon::parse($request->input('report_date'))->endOfDay()
            : now()->endOfDay();

        return array_merge(
            $balanceSheetService->buildReport($reportDate),
            ['showBreakdown' => $request->boolean('show_breakdown')],
        );
    }
}
