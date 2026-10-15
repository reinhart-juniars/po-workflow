<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Satu lembar Laporan Stok. Susunan kolom sama dengan berkas gudang klien:
 *
 *   A Nama Barang | B Stok <tgl awal> | C Satuan | D Masuk | E Keluar | F Harga
 *   G Mutasi | H Sisa Stok | I Value
 *
 * Sisa, Value, dan total ditulis sebagai rumus Excel seperti di berkas klien,
 * supaya tim gudang tetap bisa mengoreksi angka di Excel dan totalnya ikut.
 */
class StockReportSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    /** Baris pertama data (setelah judul, tanggal, kepala kolom). */
    private const FIRST_ROW = 4;

    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function __construct(
        private readonly string $title,
        private readonly Collection $rows,
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function array(): array
    {
        $openingLabel = 'STOK '.$this->from->copy()->subDay()->translatedFormat('d F');
        $period = $this->from->isSameDay($this->to)
            ? $this->to->translatedFormat('d F Y')
            : $this->from->translatedFormat('d F Y').' - '.$this->to->translatedFormat('d F Y');

        $sheet = [
            ['STOK '.$this->title.' 3S ONE'],
            ['TANGGAL :', $period],
            ['NAMA BARANG', $openingLabel, 'SATUAN', 'MASUK', 'KELUAR', 'HARGA', 'MUTASI', 'SISA STOK', 'VALUE'],
        ];

        foreach ($this->rows->values() as $index => $row) {
            $r = self::FIRST_ROW + $index;
            $sheet[] = [
                $row['name'],
                $row['opening'],
                $row['unit'],
                $row['in'] ?: null,
                $row['out'] ?: null,
                $row['price'],
                $row['mutation'] ?: null,
                "=B{$r}+D{$r}-E{$r}+G{$r}",
                "=H{$r}*F{$r}",
            ];
        }

        $last = self::FIRST_ROW + max(0, $this->rows->count() - 1);
        $sheet[] = ['TOTAL', null, null, null, null, null, null, null, $this->rows->isEmpty() ? 0 : '=SUM(I'.self::FIRST_ROW.":I{$last})"];

        return $sheet;
    }

    public function columnFormats(): array
    {
        $number = '#,##0.##';

        return ['B' => $number, 'D' => $number, 'E' => $number, 'F' => '#,##0', 'G' => $number, 'H' => $number, 'I' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        $totalRow = self::FIRST_ROW + $this->rows->count();
        $sheet->freezePane('B'.self::FIRST_ROW);

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            2 => ['font' => ['bold' => true]],
            3 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F2F2F2']]],
            $totalRow => ['font' => ['bold' => true]],
        ];
    }
}
