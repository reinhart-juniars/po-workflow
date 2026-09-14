<?php

namespace App\Exports;

use App\Models\InventoryOpening;
use App\Models\StockOpname;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export Stock Opname / Saldo Awal: satu baris per catatan nilai stok.
 *
 * Keduanya bernilai rupiah per item (qty selalu 1, unit_cost = nilai), jadi
 * berkasnya cukup memuat nilai. Kolom id dan item_id di depan supaya berkas
 * ini juga bisa diimpor kembali: id memperbarui catatan yang ada, tanpa id
 * dicocokkan berdasarkan item + tanggal.
 */
class ValueEntriesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  class-string<StockOpname|InventoryOpening>  $model
     */
    public function __construct(
        protected string $model,
        protected string $dateColumn,
        protected ?Builder $query = null,
    ) {}

    public static function opname(?Builder $query = null): self
    {
        return new self(StockOpname::class, 'opname_date', $query);
    }

    public static function opening(?Builder $query = null): self
    {
        return new self(InventoryOpening::class, 'balance_date', $query);
    }

    public function collection(): Collection
    {
        $query = $this->query ?? $this->model::query();

        return $query->with('item:id,name')->orderBy($this->dateColumn)->orderBy('id')->get();
    }

    public function headings(): array
    {
        return ['id', 'item_id', 'nama_item', 'tanggal', 'nilai', 'catatan'];
    }

    /** @param  StockOpname|InventoryOpening|Model  $row */
    public function map($row): array
    {
        return [
            $row->id,
            $row->inventory_item_id,
            $row->item?->name,
            $row->{$this->dateColumn}?->format('Y-m-d'),
            (float) $row->total_value,
            $row->notes,
        ];
    }
}
