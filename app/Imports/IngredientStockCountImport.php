<?php

namespace App\Imports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Membaca lembar Opname Bahan (IngredientStockCountExport) yang sudah diisi
 * gudang: kolom ID + Hitung Fisik, dan tanggal opname di bagian atas.
 *
 * Hasilnya hanya MENGISI grid di halaman Opname Bahan; posting ke kartu stok
 * tetap lewat tombol Simpan Opname supaya selisihnya dilihat dulu. Satu baris
 * bermasalah membatalkan seluruh berkas -- lebih baik diperbaiki di Excel
 * daripada sebagian bahan terisi tanpa disadari.
 */
class IngredientStockCountImport
{
    /** Kepala kolom dicari di baris-baris teratas (gudang kadang menyisipkan baris). */
    protected const HEADER_SEARCH_ROWS = 15;

    /** @var array<int, string> id bahan => hitung fisik (string, seperti isian form) */
    protected array $counts = [];

    /** @var list<string> */
    protected array $errors = [];

    protected ?CarbonImmutable $date = null;

    protected int $blank = 0;

    /**
     * @param  array<int, mixed>  $allowedIds  bahan yang boleh diopname (bahan di kategori stok)
     */
    public function __construct(protected array $allowedIds) {}

    public function read(string $path): static
    {
        try {
            $rows = IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            $this->errors[] = 'Berkas tidak bisa dibaca. Pastikan berkas Excel (.xlsx) hasil Export Opname Bahan.';

            return $this;
        }

        [$headerIndex, $idCol, $countCol] = $this->findHeader($rows);

        if ($headerIndex === null) {
            $this->errors[] = 'Kolom "ID" dan "Hitung Fisik" tidak ditemukan. Gunakan berkas hasil tombol Export di halaman ini.';

            return $this;
        }

        $this->date = $this->findDate(array_slice($rows, 0, $headerIndex));
        $allowed = array_flip(array_map('intval', $this->allowedIds));
        $seen = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $row) {
            $line = $index + 1; // nomor baris Excel
            $rawId = $row[$idCol] ?? null;
            $rawCount = $row[$countCol] ?? null;

            if ($this->isEmpty($rawCount)) {
                $this->blank += $this->isEmpty($rawId) ? 0 : 1;

                continue;
            }

            if (! is_numeric($rawId) || (int) $rawId != $rawId) {
                $this->errors[] = "Baris {$line}: ID kosong atau bukan angka, padahal Hitung Fisik diisi.";

                continue;
            }

            $id = (int) $rawId;

            if (! isset($allowed[$id])) {
                $this->errors[] = "Baris {$line}: bahan ID {$id} tidak ada di daftar Opname Bahan.";

                continue;
            }

            if (isset($seen[$id])) {
                $this->errors[] = "Baris {$line}: bahan ID {$id} muncul dua kali (juga di baris {$seen[$id]}).";

                continue;
            }

            $value = $this->parseNumber($rawCount);

            if ($value === null) {
                $this->errors[] = "Baris {$line}: Hitung Fisik \"{$rawCount}\" bukan angka.";

                continue;
            }

            if ($value < 0) {
                $this->errors[] = "Baris {$line}: Hitung Fisik tidak boleh negatif.";

                continue;
            }

            $seen[$id] = $line;
            $this->counts[$id] = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        if ($this->errors === [] && $this->counts === []) {
            $this->errors[] = 'Belum ada Hitung Fisik yang diisi di berkas.';
        }

        return $this;
    }

    /** @return array<int, string> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function date(): ?CarbonImmutable
    {
        return $this->date;
    }

    /** Baris bahan yang Hitung Fisik-nya dibiarkan kosong. */
    public function blank(): int
    {
        return $this->blank;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: int|null, 1: int|null, 2: int|null} indeks baris kepala, kolom ID, kolom Hitung Fisik
     */
    protected function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            $labels = array_map(fn ($cell) => Str::of((string) $cell)->lower()->squish()->toString(), $row);
            $idCol = array_search('id', $labels, true);
            $countCol = array_search('hitung fisik', $labels, true);

            if ($idCol !== false && $countCol !== false) {
                return [$index, $idCol, $countCol];
            }
        }

        return [null, null, null];
    }

    /**
     * Tanggal di samping label "Tanggal opname": tanggal Excel atau teks dd/mm/yyyy.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function findDate(array $rows): ?CarbonImmutable
    {
        foreach ($rows as $row) {
            if (Str::of((string) ($row[0] ?? ''))->lower()->squish()->toString() !== 'tanggal opname') {
                continue;
            }

            $value = $row[1] ?? null;

            try {
                if (is_numeric($value)) {
                    return CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value))->startOfDay();
                }

                if (is_string($value) && preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', trim($value), $m)) {
                    return CarbonImmutable::createSafe((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
                }
            } catch (Throwable) {
                return null;
            }

            return null;
        }

        return null;
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Angka dari sel: angka Excel apa adanya; teks ditulis cara Indonesia
     * ("1,5" atau "1.250,5") maupun cara Inggris ("1.5").
     */
    protected function parseNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = str_replace(' ', '', trim((string) $value));

        if (str_contains($text, ',')) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }
}
