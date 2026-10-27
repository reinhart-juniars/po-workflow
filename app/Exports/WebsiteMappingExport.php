<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lembar kerja pemetaan menu website (Bagian C): diisi marketing, lalu
 * diimpor kembali dengan website:mapping-import.
 */
class WebsiteMappingExport implements WithMultipleSheets
{
    /**
     * @param  list<array<string, mixed>>  $rows  hasil WebsiteMenuMapper::suggest()
     * @param  Collection<int, array<string, mixed>>  $products  WebsiteMenuMapper::productIndex()
     */
    public function __construct(
        private readonly array $rows,
        private readonly Collection $products,
    ) {}

    public function sheets(): array
    {
        return [
            new WebsiteMappingGuideSheet($this->rows),
            new WebsiteMappingSheet($this->rows, $this->products),
            new WebsiteProductListSheet($this->products),
        ];
    }
}
