<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Rekap Lembar Kerja satu tanggal kerja: pekerjaan gabungan + tugas umum di bawahnya. */
class WorkSheetRecapExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /** @param  array<string, mixed>  $recap  Hasil WorkSheetRecapService::forDate(). */
    public function __construct(
        protected array $recap,
    ) {}

    public function title(): string
    {
        return 'Rekap '.$this->recap['date']->format('d-m-Y');
    }

    public function headings(): array
    {
        return ['No.', 'Pelaksana', 'Menu', 'Pekerjaan', 'Bahan / Objek', 'Jumlah', 'Selesai'];
    }

    public function array(): array
    {
        $rows = collect($this->recap['rows'])->values()->map(fn (array $row, int $i) => [
            $i + 1,
            $row['worker'],
            implode(' + ', $row['menus']),
            $row['task'],
            $row['object'],
            $row['quantity'],
            $row['done'] ? 'Ya' : '',
        ])->all();

        if ($this->recap['chores']->isNotEmpty()) {
            $rows[] = [];
            $rows[] = ['', 'TUGAS UMUM'];

            foreach ($this->recap['chores'] as $chore) {
                $rows[] = ['', $chore->worker_name, '', $chore->task];
            }
        }

        return $rows;
    }
}
