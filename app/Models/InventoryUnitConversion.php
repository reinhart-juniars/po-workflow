<?php

namespace App\Models;

use App\Support\Units\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan konversi satuan: 1 from_unit = factor to_unit.
 *
 * Berlaku dua arah -- "1 botol = 600 ml" sekaligus berarti "1 ml = 1/600 botol".
 * Aturan tanpa bahan (inventory_item_id kosong) dipakai sebagai cadangan untuk
 * bahan yang belum punya aturan sendiri.
 */
class InventoryUnitConversion extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'from_unit',
        'to_unit',
        'factor',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'factor' => 'decimal:6',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** Aturan umum, tidak terikat pada satu bahan. */
    public function isGlobal(): bool
    {
        return $this->inventory_item_id === null;
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('inventory_item_id');
    }

    /**
     * Aturan yang berlaku untuk sebuah bahan: miliknya sendiri dan aturan umum.
     *
     * Diurutkan supaya aturan milik bahan selalu dicoba lebih dulu; aturan umum
     * hanya cadangan, dan tidak boleh menimpa keputusan yang dibuat per bahan.
     */
    public function scopeApplicableTo(Builder $query, ?int $inventoryItemId): Builder
    {
        return $query
            ->where(function (Builder $inner) use ($inventoryItemId) {
                $inner->whereNull('inventory_item_id');

                if ($inventoryItemId !== null) {
                    $inner->orWhere('inventory_item_id', $inventoryItemId);
                }
            })
            ->orderByRaw('inventory_item_id IS NULL')
            ->orderBy('id');
    }

    /** Ringkasan aturan dalam bahasa manusia, mis. "1 botol = 600 ml". */
    public function summary(): string
    {
        return sprintf(
            '1 %s = %s %s',
            $this->from_unit,
            rtrim(rtrim(number_format((float) $this->factor, 6, ',', '.'), '0'), ','),
            $this->to_unit,
        );
    }

    /**
     * Pilihan satuan untuk form, termasuk satuan lepas yang sudah ada di data.
     *
     * @return array<string, array<string, string>|string>
     */
    public static function unitOptions(?string ...$current): array
    {
        $options = Unit::groupedOptions();

        foreach ($current as $value) {
            if (filled($value) && Unit::tryFromAlias($value) === null) {
                $options['Satuan Lain'][$value] = $value;
            }
        }

        return $options;
    }
}
