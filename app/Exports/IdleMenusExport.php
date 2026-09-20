<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Export Laporan Menu Tidak Diproduksi (Bagian B.3). */
class IdleMenusExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['SKU', 'Menu', 'Satuan', 'Harga Jual', 'Punya Resep', 'Terakhir Diproduksi', 'Terakhir Terjual'];
    }

    /** @param  array<string, mixed>  $row */
    public function map($row): array
    {
        return [
            $row['sku'],
            $row['name'],
            $row['unit'],
            $row['base_price'],
            $row['has_recipe'] ? 'Ya' : 'Tidak',
            $row['last_produced'] ?? 'Belum pernah',
            $row['last_sold'] ?? 'Belum pernah',
        ];
    }
}
