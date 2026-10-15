<?php

namespace App\Exports;

use App\Services\StockReportService;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Laporan Stok (format laporan berjalan gudang): satu lembar per jenis barang.
 * Berbeda dari InventoryItemsExport, yang adalah data master untuk impor.
 */
class StockReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
    ) {}

    public function sheets(): array
    {
        $service = app(StockReportService::class);

        return collect(StockReportService::SHEETS)
            ->map(fn (string $title, string $category) => new StockReportSheet($title, $service->rows($category, $this->from, $this->to), $this->from, $this->to))
            ->values()
            ->all();
    }
}
