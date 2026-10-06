<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Laporan audit rekonsiliasi Master Menu Revamp vs PO-Workflow.
 *
 * Satu sheet per topik supaya bisa dipakai langsung sebagai lembar kerja
 * mapping bersama klien.
 *
 * @param  array<string, Collection<int, array<string, mixed>>>  $sheets
 */
class MasterMenuAuditExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(protected array $sheets) {}

    public function sheets(): array
    {
        return collect($this->sheets)
            ->map(fn (Collection $rows, string $title) => new MasterMenuAuditSheet($title, $rows))
            ->values()
            ->all();
    }
}
