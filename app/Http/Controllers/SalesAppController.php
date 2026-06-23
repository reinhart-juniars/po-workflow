<?php

namespace App\Http\Controllers;

use App\Exports\ViewExcelExport;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use App\Models\Product;
use App\Services\SalesReportService;
use App\Services\SalesActualService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class SalesAppController extends Controller
{
    public function __construct(private readonly SalesActualService $salesActualService)
    {
    }

    public function dashboard(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status', 'draft');

        if (! in_array($status, ['all', 'draft', 'submitted'], true)) {
            $status = 'draft';
        }

        $baseQuery = SalesActual::query()
            ->whereDate('sales_date', '>=', $dateFrom->toDateString())
            ->whereDate('sales_date', '<=', $dateTo->toDateString());

        $draftCount = (clone $baseQuery)->where('status', 'draft')->count();
        $submittedCount = (clone $baseQuery)->where('status', 'submitted')->count();

        $submittedItemsQuery = SalesActualItem::query()
            ->whereHas('salesActual', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'submitted')
                    ->whereBetween('submitted_at', [
                        $dateFrom->copy()->startOfDay(),
                        $dateTo->copy()->endOfDay(),
                    ]);
            });

        $totalSalesFinal = (clone $submittedItemsQuery)->sum('subtotal_actual');
        $totalReturns = (clone $submittedItemsQuery)->sum('qty_return');

        $salesActuals = (clone $baseQuery)
            ->with([
                'customer',
                'deliveryOrder',
                'items' => fn ($query) => $query->orderBy('id'),
            ])
            ->withSum('items as total_actual_amount', 'subtotal_actual')
            ->withSum('items as total_return_qty', 'qty_return')
            ->withSum('items as total_delivery_qty', 'qty_delivery')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderBy('status')
            ->orderByDesc('sales_date')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('salesapp.dashboard', compact(
            'dateFrom',
            'dateTo',
            'status',
            'draftCount',
            'submittedCount',
            'totalSalesFinal',
            'totalReturns',
            'salesActuals'
        ));
    }

    public function edit(SalesActual $salesActual)
    {
        $salesActual->load([
            'customer',
            'deliveryOrder',
            'items.product',
            'items.purchaseOrderItem.purchaseOrder',
            'items.sourceSalesActualItem.salesActual',
        ]);

        $selectedProductIds = $salesActual->items
            ->pluck('product_id')
            ->filter()
            ->values()
            ->all();

        $products = Product::query()
            ->where(function ($query) use ($selectedProductIds) {
                $query->where('active', true);

                if (! empty($selectedProductIds)) {
                    $query->orWhereIn('id', $selectedProductIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'base_price']);

        return view('salesapp.edit', compact('salesActual', 'products'));
    }

    public function update(Request $request, SalesActual $salesActual)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*.qty_actual' => ['required', 'numeric', 'min:0'],
            'items.*.qty_waste' => ['nullable', 'numeric', 'min:0'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->salesActualService->updateActualItems(
            $salesActual,
            $data['items'],
            $data['notes'] ?? null,
            $request->ip()
        );

        return redirect()
            ->route('salesapp.actuals.edit', $salesActual)
            ->with('success', 'Hasil penjualan berhasil disimpan. Retur dihitung otomatis dari selisih qty delivery dan qty actual.');
    }

    public function submit(Request $request, SalesActual $salesActual)
    {
        $this->salesActualService->submit($salesActual, $request->ip());

        return redirect()
            ->route('salesapp.dashboard')
            ->with('success', 'Penjualan final berhasil disubmit dan retur dibuat sebagai carry forward. Cash in diposting lewat Closing Penjualan di Accounting.');
    }

    public function reports(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $actuals = SalesActual::query()
            ->with(['customer', 'deliveryOrder'])
            ->withSum('items as total_actual_amount', 'subtotal_actual')
            ->withSum('items as total_return_qty', 'qty_return')
            ->where('status', 'submitted')
            ->whereBetween('submitted_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->orderByDesc('submitted_at')
            ->paginate(20)
            ->withQueryString();

        $itemsQuery = SalesActualItem::query()
            ->whereHas('salesActual', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'submitted')
                    ->whereBetween('submitted_at', [
                        $dateFrom->copy()->startOfDay(),
                        $dateTo->copy()->endOfDay(),
                    ]);
            });

        $totalSalesFinal = (clone $itemsQuery)->sum('subtotal_actual');
        $totalReturns = (clone $itemsQuery)->sum('qty_return');

        $returnItems = (clone $itemsQuery)
            ->with(['salesActual.customer', 'product', 'carryForwardItem.salesActual'])
            ->where('qty_return', '>', 0)
            ->orderByDesc('qty_return')
            ->get();

        return view('salesapp.reports', compact(
            'dateFrom',
            'dateTo',
            'actuals',
            'totalSalesFinal',
            'totalReturns',
            'returnItems'
        ));
    }

    public function wasteReport(Request $request)
    {
        return view('salesapp.waste-report', $this->buildWasteReportData($request));
    }

    public function exportWasteExcel(Request $request)
    {
        $data = $this->buildWasteReportData($request);
        $fileName = 'laporan_waste_' . $data['dateFrom']->format('Ymd') . '_' . $data['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('reports.exports.waste', $data),
            $fileName
        );
    }

    public function exportWastePdf(Request $request)
    {
        $data = $this->buildWasteReportData($request);
        $fileName = 'laporan_waste_' . $data['dateFrom']->format('Ymd') . '_' . $data['dateTo']->format('Ymd') . '.pdf';

        return Pdf::loadView('reports.exports.waste', $data)
            ->setPaper('a4', 'landscape')
            ->download($fileName);
    }

    private function buildWasteReportData(Request $request): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $wasteItems = SalesActualItem::query()
            ->with(['salesActual.customer', 'product'])
            ->where('qty_waste', '>', 0)
            ->whereHas('salesActual', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'submitted')
                    ->whereDate('sales_date', '>=', $dateFrom->toDateString())
                    ->whereDate('sales_date', '<=', $dateTo->toDateString());
            })
            ->get()
            ->sortBy([
                fn (SalesActualItem $item) => $item->salesActual?->sales_date?->toDateString(),
                fn (SalesActualItem $item) => $item->item_name,
            ])
            ->values();

        $totalWasteQty = round((float) $wasteItems->sum('qty_waste'), 2);
        $totalWasteCost = round((float) $wasteItems->sum(
            fn (SalesActualItem $item) => (float) $item->qty_waste
                * ((float) $item->raw_material_cost + (float) $item->overhead_cost)
        ), 2);
        $totalWasteSelling = round((float) $wasteItems->sum(
            fn (SalesActualItem $item) => (float) $item->qty_waste * (float) $item->unit_price
        ), 2);

        return compact(
            'dateFrom',
            'dateTo',
            'wasteItems',
            'totalWasteQty',
            'totalWasteCost',
            'totalWasteSelling'
        );
    }

    public function salesReport(Request $request, SalesReportService $salesReportService)
    {
        $reportData = $salesReportService->buildReportData($request);
        $reportData['useSectionLayout'] = true;

        return view('reports.sales', array_merge($reportData, [
            'pageLayout' => $reportData['viewMode'] === 'full' ? 'layouts.sales-report' : 'layouts.salesapp',
            'reportRouteName' => 'salesapp.reports.sales',
            'reportExcelRouteName' => 'salesapp.reports.sales.export.excel',
            'reportPdfRouteName' => 'salesapp.reports.sales.export.pdf',
            'dashboardRouteName' => 'salesapp.dashboard',
            'dashboardLabel' => 'Dashboard Sales',
        ]));
    }

    public function exportSalesExcel(Request $request, SalesReportService $salesReportService)
    {
        $reportData = $salesReportService->buildReportData($request);
        $reportData['useSectionLayout'] = true;
        $fileName = 'laporan_penjualan_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.xlsx';

        return Excel::download(
            new ViewExcelExport('reports.exports.sales', $reportData),
            $fileName
        );
    }

    public function exportSalesPdf(Request $request, SalesReportService $salesReportService)
    {
        $reportData = $salesReportService->buildReportData($request);
        $reportData['useSectionLayout'] = true;
        $fileName = 'laporan_penjualan_' . $reportData['dateFrom']->format('Ymd') . '_' . $reportData['dateTo']->format('Ymd') . '.pdf';

        $paper = $salesReportService->pdfPaperSize($reportData, true);
        $orientation = is_array($paper) ? 'portrait' : 'landscape';

        return Pdf::loadView('reports.exports.sales', $reportData)
            ->setPaper($paper, $orientation)
            ->download($fileName);
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
