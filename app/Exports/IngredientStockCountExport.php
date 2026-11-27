<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar Opname Bahan untuk dicetak gudang, diisi hitung fisiknya, lalu
 * diunggah kembali lewat Import di halaman Opname Bahan.
 *
 *   A No | B ID | C Kategori | D Bahan | E Satuan | F Saldo Kartu Stok
 *   G Hitung Fisik (diisi) | H Selisih (rumus) | I Catatan
 *
 * Kolom ID dan tanggal di B2 dibaca kembali saat import
 * (IngredientStockCountImport); kolom lain hanya bantuan membaca.
 */
class IngredientStockCountExport implements FromArray, WithColumnWidths, WithEvents, WithStyles, WithTitle
{
    /** Baris kepala kolom; data mulai baris sesudahnya. */
    public const HEADER_ROW = 5;

    public const HEADINGS = ['No', 'ID', 'Kategori', 'Bahan', 'Satuan', 'Saldo Kartu Stok', 'Hitung Fisik', 'Selisih', 'Catatan'];

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  baris IngredientStockCount::getRows()
     */
    public function __construct(
        protected Collection $rows,
        protected CarbonInterface $date,
        protected string $categoryLabel,
    ) {}

    public function title(): string
    {
        return 'Opname Bahan';
    }

    public function array(): array
    {
        $sheet = [
            ['LEMBAR OPNAME BAHAN'],
            ['Tanggal opname', Date::dateTimeToExcel($this->date)],
            ['Kategori', $this->categoryLabel],
            ['Isi kolom Hitung Fisik (dalam satuan di kolom Satuan). Baris yang dikosongkan tidak diubah. Jangan mengubah kolom ID.'],
            self::HEADINGS,
        ];

        foreach ($this->rows->values() as $index => $row) {
            $r = self::HEADER_ROW + 1 + $index;
            $sheet[] = [
                $index + 1,
                $row['id'],
                $row['category'],
                $row['name'],
                $row['unit'],
                // Bahan yang belum pernah tercatat di kartu stok: hitungan pertamanya jadi saldo awal.
                $row['tracked'] ? $row['balance'] : null,
                null,
                "=IF(G{$r}=\"\",\"\",IF(F{$r}=\"\",\"Saldo awal\",G{$r}-F{$r}))",
                null,
            ];
        }

        return $sheet;
    }

    public function columnWidths(): array
    {
        return ['A' => 5, 'B' => 7, 'C' => 16, 'D' => 38, 'E' => 9, 'F' => 13, 'G' => 13, 'H' => 11, 'I' => 22];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            'A2:A3' => ['font' => ['bold' => true]],
            4 => ['font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '555555']]],
            self::HEADER_ROW => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = max(self::HEADER_ROW, self::HEADER_ROW + $this->rows->count());
                $table = 'A'.self::HEADER_ROW.':I'.$last;

                // Format diatur di sini, bukan WithColumnFormatting: kunci "B2" di sana dibaca
                // sebagai kolom dan membuat sel kosong bergaya B21:B2n di bawah tabel.
                $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
                $sheet->getStyle('B2')->getAlignment()->setHorizontal('left');
                if ($last > self::HEADER_ROW) {
                    $sheet->getStyle('F'.(self::HEADER_ROW + 1).':H'.$last)->getNumberFormat()->setFormatCode('#,##0.####;-#,##0.####;0');
                }
                $sheet->getStyle($table)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                // Kolom isian diberi warna supaya jelas mana yang ditulis gudang.
                if ($last > self::HEADER_ROW) {
                    $sheet->getStyle('G'.(self::HEADER_ROW + 1).':G'.$last)->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF9C3');
                }
                // Baris lebih tinggi: cukup untuk ditulis tangan di kertas.
                for ($r = self::HEADER_ROW + 1; $r <= $last; $r++) {
                    $sheet->getRowDimension($r)->setRowHeight(20);
                }
                $sheet->getStyle('B'.(self::HEADER_ROW + 1).':B'.$last)->getFont()->getColor()->setRGB('6B7280');

                // Cetak: A4 tegak, selebar satu halaman, kepala kolom berulang tiap halaman.
                $setup = $sheet->getPageSetup();
                $setup->setPaperSize(PageSetup::PAPERSIZE_A4)
                    ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
                    ->setFitToWidth(1)
                    ->setFitToHeight(0)
                    ->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW)
                    ->setPrintArea('A1:I'.$last);
                $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
                $sheet->getHeaderFooter()->setOddFooter('&LDihitung oleh: ________________   Diperiksa: ________________&RHalaman &P / &N');
                $sheet->freezePane('A'.(self::HEADER_ROW + 1));
            },
        ];
    }
}
