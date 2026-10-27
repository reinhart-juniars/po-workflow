<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Lembar "Daftar Produk": semua produk aktif untuk mencari ID yang dipilih. */
class WebsiteProductListSheet implements FromArray, WithColumnWidths, WithEvents, WithStyles, WithTitle
{
    /** @param  Collection<int, array<string, mixed>>  $products */
    public function __construct(private readonly Collection $products) {}

    public function title(): string
    {
        return 'Daftar Produk';
    }

    public function array(): array
    {
        $brands = \App\Support\WebsiteCatalog::brands();

        return array_merge(
            [['ID', 'NAMA DI MASTER MENU', 'SKU SEKARANG', 'BRAND', 'JUMLAH PESANAN (BARIS PO)']],
            $this->products->sortBy('name')->map(fn ($p) => [$p['id'], $p['name'], $p['sku'], $brands[$p['brand']]['label'], $p['orders']])->values()->all(),
        );
    }

    public function columnWidths(): array
    {
        return ['A' => 8, 'B' => 48, 'C' => 40, 'D' => 24, 'E' => 14];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E2E8F0']]]];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:E'.($this->products->count() + 1));
            },
        ];
    }
}
