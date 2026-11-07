<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet utama Lembar SPK PRA, dengan kolom dan warna yang sama seperti berkas
 * yang selama ini dikirim gudang ke produksi. Judul komponen ditebalkan.
 */
class SpkPraExport implements FromArray, WithColumnWidths, WithStyles, WithTitle
{
    /** @var list<int> Nomor baris Excel berisi judul komponen. */
    protected array $boldRows = [];

    /** @var list<list<mixed>>|null */
    protected ?array $rows = null;

    /** @param  array{order: \App\Models\ProductionOrder, menus: list<array<string, mixed>>}  $sheet */
    public function __construct(
        protected array $sheet,
    ) {}

    public function title(): string
    {
        return 'SPK PRA';
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return $this->rows ??= $this->buildRows();
    }

    /** @return list<list<mixed>> */
    protected function buildRows(): array
    {
        $order = $this->sheet['order'];

        $rows = [
            ['', '', 'SPK PRA untuk dikerjakan tgl', $order->workDate()?->format('d/m/Y'), 'jam', $order->production_time],
            ['', 'No', 'Order', 'QTY', 'Satuan', 'Pelaksana'],
        ];

        foreach ($this->sheet['menus'] as $menu) {
            $rows[] = ['', $menu['no'], $menu['title'], $menu['qty'], $menu['unit'], $menu['note']];

            foreach ($menu['components'] as $component) {
                if ($component['name'] !== null) {
                    $rows[] = ['', '', $component['name'], $component['show_qty'] ? round((float) $component['qty'], 2) : null, $component['show_qty'] ? $component['unit'] : null, $component['note']];
                    $this->boldRows[] = count($rows);
                }

                foreach ($component['rows'] as $row) {
                    $rows[] = ['', '', $row['name'], $row['qty'], $row['unit']];
                }
            }

            foreach ($menu['issues'] as $issue) {
                $rows[] = ['', '', 'catatan: '.$issue];
            }

            // Harus berisi satu sel: baris [] dibuang Laravel Excel dan nomor baris tebal ikut bergeser.
            $rows[] = [''];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 3.9, 'B' => 4.8, 'C' => 48.9, 'D' => 9.6, 'E' => 7.5, 'F' => 17.4];
    }

    public function styles(Worksheet $sheet): array
    {
        $last = count($this->array());

        $sheet->getStyle('B1:F'.$last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEADA');

        foreach ($this->boldRows as $row) {
            $sheet->getStyle('C'.$row)->getFont()->setBold(true);
        }

        return [];
    }
}
