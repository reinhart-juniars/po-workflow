<?php

namespace App\Imports;

use App\Support\NumberParser;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Membaca Excel "rencana masak" mingguan klien (revisi v3.1).
 *
 * Bentuk berkasnya bukan tabel, melainkan beberapa blok per hari yang
 * disusun berdampingan (mis. Senin-Rabu di kolom A-C, Kamis-Sabtu di E-G):
 *
 *     [tanggal (kuning)] [        ] [nama hari]
 *     nama menu          harga      qty            <- menu harian (stok siap)
 *     ...
 *                                   =SUM(...)      <- total (oranye)
 *     pesanan khusus     harga      qty            <- PO customer, BUKAN stok siap
 *
 * Kepala blok dikenali dari sel bertanggal yang dua kolom di kanannya berisi
 * nama hari, bukan dari warna. Tanggal berupa rumus (=A3+1), jadi yang dibaca
 * nilai hasil hitungnya. Pembatas menu harian vs pesanan khusus adalah sel qty
 * berisi rumus SUM; blok tanpa SUM dianggap seluruhnya menu harian.
 */
class ReadyStockPlanReader
{
    public const DAY_NAMES = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', "jum'at", 'sabtu', 'minggu'];

    /**
     * @return list<array{
     *     date: string, day: string, sheet_total: ?float,
     *     items: list<array{name: string, price: ?float, qty: float, row: int}>,
     *     specials: list<array{name: string, price: ?float, qty: ?float, row: int}>
     * }>
     */
    public function read(string $path): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('Berkas tidak bisa dibaca sebagai Excel. Simpan ulang sebagai .xlsx lalu coba lagi.');
        }

        $days = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            foreach ($this->headers($sheet) as $header) {
                $days[] = $this->readBlock($sheet, $header);
            }
        }

        if ($days === []) {
            throw new RuntimeException('Tidak ditemukan blok tanggal. Setiap hari harus diawali sel tanggal dengan nama hari (senin, selasa, ...) dua kolom di kanannya.');
        }

        usort($days, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $days;
    }

    /**
     * Sel kepala blok: tanggal + nama hari dua kolom di kanannya.
     *
     * @return list<array{col: int, row: int, date: string, day: string}>
     */
    private function headers(Worksheet $sheet): array
    {
        $headers = [];
        $maxRow = $sheet->getHighestDataRow();
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $maxRow; $row++) {
            for ($col = 1; $col <= $maxCol - 2; $col++) {
                $day = mb_strtolower(trim((string) $this->value($sheet, $col + 2, $row)));

                if (! in_array($day, self::DAY_NAMES, true)) {
                    continue;
                }

                $date = $this->dateOf($sheet->getCell([$col, $row]));

                if ($date !== null) {
                    $headers[] = ['col' => $col, 'row' => $row, 'date' => $date, 'day' => $day];
                }
            }
        }

        return $headers;
    }

    /** @param  array{col: int, row: int, date: string, day: string}  $header */
    private function readBlock(Worksheet $sheet, array $header): array
    {
        $col = $header['col'];
        $maxRow = $sheet->getHighestDataRow();
        $items = [];
        $specials = [];
        $sheetTotal = null;
        $afterTotal = false;

        for ($row = $header['row'] + 1; $row <= $maxRow; $row++) {
            // Blok berikutnya di kolom yang sama = blok ini selesai.
            if ($this->isHeaderAt($sheet, $col, $row)) {
                break;
            }

            $qtyCell = $sheet->getCell([$col + 2, $row]);
            $raw = $qtyCell->getValue();

            if (is_string($raw) && preg_match('/^=\s*SUM\s*\(/i', $raw)) {
                $sheetTotal = (float) $qtyCell->getCalculatedValue();
                $afterTotal = true;

                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', (string) $this->value($sheet, $col, $row)));
            $price = $this->number($this->value($sheet, $col + 1, $row));
            $qty = $this->number($this->value($sheet, $col + 2, $row));

            // Label tanpa angka di baris total (mis. "pesanan") dan baris kosong dilewati.
            if ($name === '' || (mb_strtolower($name) === 'pesanan' && $qty === null)) {
                continue;
            }

            if ($afterTotal || $qty === null) {
                $specials[] = ['name' => $name, 'price' => $price, 'qty' => $qty, 'row' => $row];

                continue;
            }

            $items[] = ['name' => $name, 'price' => $price, 'qty' => $qty, 'row' => $row];
        }

        return [
            'date' => $header['date'],
            'day' => $header['day'],
            'sheet_total' => $sheetTotal,
            'items' => $items,
            'specials' => $specials,
        ];
    }

    private function isHeaderAt(Worksheet $sheet, int $col, int $row): bool
    {
        $day = mb_strtolower(trim((string) $this->value($sheet, $col + 2, $row)));

        return in_array($day, self::DAY_NAMES, true) && $this->dateOf($sheet->getCell([$col, $row])) !== null;
    }

    private function value(Worksheet $sheet, int $col, int $row): mixed
    {
        return $sheet->getCell([$col, $row])->getCalculatedValue();
    }

    /** Tanggal dari sel: angka seri Excel (termasuk hasil rumus) atau teks dd/mm/yyyy. */
    private function dateOf(Cell $cell): ?string
    {
        $value = $cell->getCalculatedValue();

        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        if (is_string($value) && preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', trim($value), $m)) {
            return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
        }

        return null;
    }

    private function number(mixed $value): ?float
    {
        return NumberParser::parse($value);
    }
}
