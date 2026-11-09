<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barang Sisa yang keluar dari stok tanpa pernah dijual.
 *
 * - waste: dibuang (tidak layak jual). Nilainya keluar dari persediaan Barang
 *   Sisa pada tanggal dibuang dan dilaporkan sebagai Barang Sisa Dibuang.
 * - cancel: batal, porsinya ternyata tidak jadi dibuat. Stoknya berkurang,
 *   tapi tidak dilaporkan sebagai waste karena bahan bakunya tidak terpakai.
 *
 * Lihat LeftoverStockService.
 */
class LeftoverDisposal extends Model
{
    public const KIND_WASTE = 'waste';

    public const KIND_CANCEL = 'cancel';

    protected $fillable = [
        'source_sales_actual_item_id',
        'leftover_component_id',
        'kind',
        'disposed_at',
        'qty',
        'reason',
        'created_by',
    ];

    protected $attributes = [
        'kind' => self::KIND_WASTE,
    ];

    protected $casts = [
        'disposed_at' => 'date',
        'qty' => 'decimal:2',
    ];

    /** Hanya yang benar-benar dibuang (waste), tanpa yang batal dibuat. */
    public function scopeWaste(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_WASTE);
    }

    public function isCancel(): bool
    {
        return $this->kind === self::KIND_CANCEL;
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SalesActualItem::class, 'source_sales_actual_item_id');
    }

    /** Komponen rincian yang dibuang; null berarti porsi utuh dari entrinya. */
    public function component(): BelongsTo
    {
        return $this->belongsTo(LeftoverComponent::class, 'leftover_component_id');
    }

    /** Nilai HPP yang dibuang: per satuan komponen, atau HPP menu entrinya. */
    public function value(): float
    {
        $unitCost = $this->leftover_component_id
            ? (float) $this->component?->unitCost()
            : (float) $this->sourceItem?->leftoverUnitCost();

        return round((float) $this->qty * $unitCost, 2);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
