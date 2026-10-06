<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Breakdown bahan (MaterialBreakdownService) dalam dua sheet: rekap bahan &
 * stok untuk belanja, dan rincian per menu untuk dapur (padanan export Pra
 * SPK Master Menu).
 */
class MaterialBreakdownExport implements WithMultipleSheets
{
    /** @param  array<string, mixed>  $breakdown */
    public function __construct(
        protected array $breakdown,
    ) {}

    public function sheets(): array
    {
        return [
            new MaterialBreakdownSheet('Rekap Bahan', $this->recapRows()),
            new MaterialBreakdownSheet('Per Menu', $this->menuRows()),
        ];
    }

    /** @return list<list<mixed>> */
    protected function recapRows(): array
    {
        $rows = [['Bahan', 'Kebutuhan', 'Stok Sistem', 'Perlu Beli', 'Satuan', 'Harga Satuan', 'Est. Biaya Beli', 'Dipakai oleh']];

        foreach ($this->breakdown['recap'] as $row) {
            $rows[] = [
                $row['name'], $row['qty'], $row['stock'] ?? 'belum tercatat', $row['to_buy'], $row['unit'],
                $row['unit_price'] !== null ? (float) $row['unit_price'] : null, $row['to_buy_cost'], implode(', ', $row['menus']),
            ];
        }

        foreach ($this->breakdown['missing'] as $menu) {
            $rows[] = ['BELUM ADA RESEP: '.$menu['label'].' ('.$menu['qty'].' '.$menu['unit'].')'];
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    protected function menuRows(): array
    {
        $rows = [['Menu / Bahan', 'Jumlah', 'Satuan', 'Biaya']];

        foreach ($this->breakdown['menus'] as $menu) {
            $rows[] = [$menu['label'].($menu['recipe'] !== $menu['label'] ? ' (resep: '.$menu['recipe'].')' : ''), $menu['qty'], $menu['unit'], $menu['total_cost']];

            foreach ($menu['rows'] as $row) {
                $rows[] = ['    '.$row['name'], $row['qty'], $row['unit'], $row['total_cost']];
            }

            foreach ($menu['issues'] as $issue) {
                $rows[] = ['    catatan: '.$issue];
            }

            $rows[] = [];
        }

        return $rows;
    }
}
