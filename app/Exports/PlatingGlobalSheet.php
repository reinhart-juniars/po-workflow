<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Sheet "Komponen Global" lembar plating: satu baris per komponen. */
class PlatingGlobalSheet implements FromArray, ShouldAutoSize, WithTitle
{
    /** @param array{rows: array<int, array<string, mixed>>, total_qty: float} $sheet */
    public function __construct(protected array $sheet) {}

    public function title(): string
    {
        return 'Komponen Global';
    }

    public function array(): array
    {
        $rows = [['No.', 'Nama Menu', 'Qty', 'Satuan', 'Komponen', 'Keterangan']];

        foreach ($this->sheet['rows'] as $i => $menu) {
            $components = $menu['components'] ?: ['— belum ada sub-menu —'];

            foreach ($components as $j => $component) {
                $rows[] = $j === 0
                    ? [$i + 1, $menu['name'], (float) $menu['qty'], $menu['unit'], $component, $menu['remark']]
                    : ['', '', '', '', $component, ''];
            }
        }

        $rows[] = ['', 'Total Produksi', (float) $this->sheet['total_qty'], 'porsi', '', ''];

        return $rows;
    }
}
