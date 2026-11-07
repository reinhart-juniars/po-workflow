<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Berkas Excel SPK PRA: lembar untuk produksi, lalu rekap seluruh bahan resep.
 * Rekap tanpa harga dan stok karena berkasnya dikirim ke dapur, dan tetap memuat
 * kebutuhan penuh: bahan yang disembunyikan dari lembar tetap harus disiapkan.
 */
class SpkPraWorkbook implements WithMultipleSheets
{
    /**
     * @param  array{order: \App\Models\ProductionOrder, menus: list<array<string, mixed>>}  $sheet
     * @param  array<string, mixed>  $breakdown  Hasil MaterialBreakdownService.
     */
    public function __construct(
        protected array $sheet,
        protected array $breakdown,
    ) {}

    public function sheets(): array
    {
        return [
            new SpkPraExport($this->sheet),
            new MaterialBreakdownSheet('Rekap Bahan', $this->recapRows()),
        ];
    }

    /** @return list<list<mixed>> */
    protected function recapRows(): array
    {
        $rows = [['No.', 'Bahan', 'Total Qty', 'Satuan', 'Dari brp menu']];

        foreach ($this->breakdown['recap'] as $index => $row) {
            $rows[] = [$index + 1, $row['name'], $row['qty'], $row['unit'], count($row['menus'])];
        }

        return $rows;
    }
}
