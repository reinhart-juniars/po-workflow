<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Satu sheet laporan audit. Heading diambil dari kunci baris pertama, jadi
 * penambahan kolom di service otomatis ikut terbawa ke Excel.
 */
class MasterMenuAuditSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        protected string $title,
        protected Collection $rows
    ) {}

    public function title(): string
    {
        // Excel membatasi nama sheet 31 karakter dan melarang beberapa simbol.
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $this->title) ?? $this->title, 0, 31);
    }

    public function headings(): array
    {
        $first = $this->rows->first();

        return is_array($first) ? array_keys($first) : [];
    }

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row) => array_values((array) $row));
    }
}
