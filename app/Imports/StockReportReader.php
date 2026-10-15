<?php

namespace App\Imports;

use App\Models\InventoryItem;
use App\Support\NumberParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Membaca laporan stok harian gudang (format Excel yang dipakai tim selama
 * ini, mis. "Stok 3S 14 Oktober 2026.xlsx"), untuk mengisi Item Inventaris.
 *
 * Bentuknya bukan satu tabel, melainkan beberapa sheet yang masing-masing
 * punya judul di atas dan kepala kolom di baris ke-2 s/d ke-10:
 *
 *     STOCK 3S
 *                                                  TANGGAL : 14/10/2026
 *     NAMA BARANG | STOCK 13 Okt | SATUAN | MASUK | KELUAR | HARGA | ... | SISA STOK | VALUE
 *
 * Letak kolom berbeda per sheet, jadi kolom dikenali dari teks kepalanya.
 * Jenis item diambil dari nama sheet (BAHAN BAKU / KEMASAN / PERALATAN /
 * INVENTARIS). Sheet tanpa kepala "Nama Barang" (catatan, daftar pengurangan,
 * salinan mentah) dilewati dan dilaporkan, bukan ditebak isinya.
 */
class StockReportReader
{
    /** Baris teratas yang diperiksa untuk mencari kepala kolom. */
    private const HEADER_SCAN_ROWS = 10;

    /** Kolom terkiri yang diperiksa; sheet klien ada yang berformat sampai kolom JF. */
    private const HEADER_SCAN_COLS = 20;

    /**
     * @return array{
     *     rows: list<array{sheet: string, row: int, name: string, unit: ?string, category: string, price: ?float, qty: ?float}>,
     *     sheets: array<string, int>,
     *     skipped: array<string, string>,
     *     date: ?string
     * }
     */
    public function read(string $path): array
    {
        try {
            $book = $this->load($path);
        } catch (\Throwable) {
            throw new RuntimeException('Berkas tidak bisa dibaca sebagai Excel. Simpan ulang sebagai .xlsx lalu coba lagi.');
        }

        $rows = [];
        $sheets = [];
        $skipped = [];
        $date = null;

        foreach ($book->getWorksheetIterator() as $sheet) {
            $title = trim($sheet->getTitle());
            $header = $this->header($sheet);

            if ($header === null) {
                // Sheet kosong tidak perlu dilaporkan.
                if ($sheet->getHighestDataRow() > 1 || $sheet->getCell('A1')->getValue() !== null) {
                    $skipped[$title] = 'tidak ada kolom "Nama Barang"';
                }

                continue;
            }

            $category = self::categoryForSheet($title);

            if ($category === null) {
                $skipped[$title] = 'jenis item tidak dikenali dari nama sheet (pakai BAHAN BAKU, KEMASAN, PERALATAN, atau INVENTARIS)';

                continue;
            }

            $date ??= $this->reportDate($sheet, $header['row']);

            $count = 0;
            foreach ($this->dataRows($sheet, $header) as $row) {
                $rows[] = ['sheet' => $title, 'category' => $category] + $row;
                $count++;
            }

            $sheets[$title] = $count;
        }

        return ['rows' => $rows, 'sheets' => $sheets, 'skipped' => $skipped, 'date' => $date];
    }

    /**
     * Berkas ini laporan stok gudang (bukan format Export Data Master)?
     * Ya bila tidak ada sheet yang baris pertamanya memuat "nama_item",
     * tetapi ada sheet dengan kepala "Nama Barang" di baris-baris atas.
     */
    public function looksLikeStockReport(string $path): bool
    {
        try {
            $book = $this->load($path);
        } catch (\Throwable) {
            return false;
        }

        $found = false;

        foreach ($book->getWorksheetIterator() as $sheet) {
            for ($col = 1; $col <= self::HEADER_SCAN_COLS; $col++) {
                if ($this->normalize($this->value($sheet, $col, 1)) === 'nama item') {
                    return false;
                }
            }

            $found = $found || $this->header($sheet) !== null;
        }

        return $found;
    }

    /** Jenis item dari nama sheet; null bila tidak bisa dipastikan. */
    public static function categoryForSheet(string $title): ?string
    {
        $title = mb_strtolower($title);

        return match (true) {
            str_contains($title, 'kemasan') || str_contains($title, 'packaging') => InventoryItem::CATEGORY_PACKAGING,
            str_contains($title, 'peralatan') || str_contains($title, 'inventaris') => InventoryItem::CATEGORY_FIXED_ASSET,
            str_contains($title, 'bahan') => InventoryItem::CATEGORY_RAW_MATERIAL,
            default => null,
        };
    }

    /**
     * Baris dan kolom kepala: kolom nama wajib; satuan, harga, sisa, qty, dan
     * total opsional.
     *
     * @return array{row: int, name: int, unit: ?int, price: ?int, qty: ?int, total: ?int}|null
     */
    private function header(Worksheet $sheet): ?array
    {
        $maxRow = min(self::HEADER_SCAN_ROWS, $sheet->getHighestDataRow());

        for ($row = 1; $row <= $maxRow; $row++) {
            $columns = [];

            for ($col = 1; $col <= self::HEADER_SCAN_COLS; $col++) {
                $text = $this->normalize($this->value($sheet, $col, $row));

                if ($text === '') {
                    continue;
                }

                $key = match (true) {
                    in_array($text, ['nama barang', 'nama inventaris', 'nama item', 'nama bahan'], true) => 'name',
                    $text === 'satuan' => 'unit',
                    str_starts_with($text, 'harga') => 'price',
                    str_starts_with($text, 'sisa') => 'qty',
                    $text === 'qty' => 'qty_fallback',
                    str_starts_with($text, 'value') || $text === 'total' => 'total',
                    default => null,
                };

                if ($key !== null && ! isset($columns[$key])) {
                    $columns[$key] = $col;
                }
            }

            if (isset($columns['name'])) {
                return [
                    'row' => $row,
                    'name' => $columns['name'],
                    'unit' => $columns['unit'] ?? null,
                    'price' => $columns['price'] ?? null,
                    'qty' => $columns['qty'] ?? $columns['qty_fallback'] ?? null,
                    'total' => $columns['total'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array{row: int, name: int, unit: ?int, price: ?int, qty: ?int, total: ?int}  $header
     * @return iterable<array{row: int, name: string, unit: ?string, price: ?float, qty: ?float}>
     */
    private function dataRows(Worksheet $sheet, array $header): iterable
    {
        $maxRow = $sheet->getHighestDataRow();
        $cell = fn (?int $col, int $row) => $col === null ? null : $this->value($sheet, $col, $row);

        for ($row = $header['row'] + 1; $row <= $maxRow; $row++) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $cell($header['name'], $row)));

            // Baris kosong, baris total, dan sel nama berisi angka (nomor urut) bukan barang.
            if ($name === '' || preg_match('/^(grand\s+)?total\b/i', $name) || is_numeric($name)) {
                continue;
            }

            $unit = trim((string) $cell($header['unit'], $row));
            $qty = NumberParser::parse($cell($header['qty'], $row));
            $price = NumberParser::parse($cell($header['price'], $row));

            // Sheet tanpa kolom harga (mis. daftar inventaris WWP): harga = total ÷ qty.
            if ($price === null && $header['total'] !== null && $qty) {
                $total = NumberParser::parse($cell($header['total'], $row));
                $price = $total === null ? null : round($total / $qty, 4);
            }

            yield [
                'row' => $row,
                'name' => $name,
                'unit' => $unit === '' ? null : $unit,
                'price' => $price,
                'qty' => $qty,
            ];
        }
    }

    /**
     * Muat hanya data, tanpa format: laporan gudang klien (format sampai
     * kolom JF, ribuan sel berwarna) butuh ±13 detik dimuat lengkap, ±1 detik
     * data saja -- beda antara lolos dan kena batas waktu request di server.
     */
    private function load(string $path): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        return $reader->load($path);
    }

    /**
     * Nilai sel. Untuk rumus dipakai nilai terakhir yang dihitung Excel
     * (tersimpan di berkas); dihitung ulang hanya bila nilai itu tidak ada.
     */
    private function value(Worksheet $sheet, int $col, int $row): mixed
    {
        if (! $sheet->cellExists([$col, $row])) {
            return null;
        }

        $cell = $sheet->getCell([$col, $row]);

        if (! $cell->isFormula()) {
            return $cell->getValue();
        }

        return $cell->getOldCalculatedValue() ?? $cell->getCalculatedValue();
    }

    /** Tanggal laporan: sel di kanan label "TANGGAL :" di atas kepala kolom. */
    private function reportDate(Worksheet $sheet, int $headerRow): ?string
    {
        for ($row = 1; $row < $headerRow; $row++) {
            for ($col = 1; $col < self::HEADER_SCAN_COLS; $col++) {
                if ($this->normalize($this->value($sheet, $col, $row)) !== 'tanggal') {
                    continue;
                }

                for ($next = $col + 1; $next <= min($col + 3, self::HEADER_SCAN_COLS); $next++) {
                    $value = $this->value($sheet, $next, $row);

                    if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
                        return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
                    }

                    if (is_string($value) && preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', trim($value), $m)) {
                        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    }
                }
            }
        }

        return null;
    }

    private function normalize(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        // "HARGA/UNIT (RP)" -> "harga unit rp"; "nama_item" -> "nama item".
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }
}
