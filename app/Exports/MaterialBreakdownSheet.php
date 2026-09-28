<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Satu sheet berisi baris jadi, dipakai export breakdown bahan. */
class MaterialBreakdownSheet implements FromArray, ShouldAutoSize, WithTitle
{
    /** @param  list<list<mixed>>  $rows */
    public function __construct(
        protected string $title,
        protected array $rows,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
