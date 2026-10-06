<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(
        protected Collection $products
    ) {
    }

    public function collection(): Collection
    {
        return $this->products;
    }

    public function headings(): array
    {
        return [
            'id',
            'sku',
            'nama_menu',
            'satuan',
            'bahan_baku',
            'overhead_cost',
            'profit',
            'harga_jual',
            'menu_3s',
            'aktif',
        ];
    }

    public function map($product): array
    {
        /** @var Product $product */
        return [
            $product->id,
            $product->sku,
            $product->name,
            $product->unit,
            $product->raw_material_cost !== null ? (float) $product->raw_material_cost : null,
            $product->overhead_cost !== null ? (float) $product->overhead_cost : null,
            $product->profit !== null ? (float) $product->profit : null,
            (float) $product->base_price,
            $product->is_3s ? 1 : 0,
            $product->active ? 1 : 0,
        ];
    }
}
