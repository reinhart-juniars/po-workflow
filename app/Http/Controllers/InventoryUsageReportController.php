<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Models\InventoryItem;
use App\Services\InventoryUsageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class InventoryUsageReportController extends Controller
{
    public function index(Request $request, InventoryUsageService $inventoryUsageService): View
    {
        return view('inventory.reports.usage', $this->buildReportData($request, $inventoryUsageService));
    }

    public function exportExcel(Request $request, InventoryUsageService $inventoryUsageService)
    {
        $reportData = $this->buildReportData($request, $inventoryUsageService);

        if (! $reportData['summary']) {
            return redirect()
                ->route('accountingapp.reports.inventory-usage', $request->query())
                ->with('error', 'Pilih item terlebih dahulu sebelum export laporan.');
        }

        $itemSlug = str_replace(' ', '_', $reportData['selectedItem']?->name ?? 'item');
        $fileName = 'laporan_pemakaian_bahan_' . $itemSlug . '_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('accountingapp.reports.exports.inventory-usage', $reportData),
            $fileName
        );
    }

    public function exportPdf(Request $request, InventoryUsageService $inventoryUsageService)
    {
        $reportData = $this->buildReportData($request, $inventoryUsageService);

        if (! $reportData['summary']) {
            return redirect()
                ->route('accountingapp.reports.inventory-usage', $request->query())
                ->with('error', 'Pilih item terlebih dahulu sebelum export laporan.');
        }

        $itemSlug = str_replace(' ', '_', $reportData['selectedItem']?->name ?? 'item');
        $fileName = 'laporan_pemakaian_bahan_' . $itemSlug . '_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.pdf';

        return Pdf::loadView('accountingapp.reports.exports.inventory-usage', $reportData)
            ->setPaper('a4', 'portrait')
            ->download($fileName);
    }

    protected function buildReportData(Request $request, InventoryUsageService $inventoryUsageService): array
    {
        $items = InventoryItem::query()
            ->where('is_active', true)
            ->whereIn('category', InventoryItem::stockCategories())
            ->orderBy('name')
            ->get();

        $selectedItemId = $request->integer('inventory_item_id');
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))
            : now()->startOfMonth();
        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))
            : now()->endOfMonth();

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $summary = null;
        $selectedItem = null;
        $detailRows = collect();

        if ($selectedItemId) {
            $selectedItem = $items->firstWhere('id', $selectedItemId);
            $report = $inventoryUsageService->buildItemReport($selectedItemId, $dateFrom, $dateTo);
            $summary = $report['summary'];
            $detailRows = $report['detail_rows'];
        }

        return [
            'items' => $items,
            'selectedItemId' => $selectedItemId,
            'selectedItem' => $selectedItem,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'summary' => $summary,
            'detailRows' => $detailRows,
        ];
    }
}
