<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barang Sisa yang dibuang dari stok (tidak layak jual) tanpa pernah dijual.
 *
 * Nilainya keluar dari persediaan Barang Sisa pada tanggal dibuang, jadi masuk
 * HPP periode itu. Lihat LeftoverStockService.
 */
class LeftoverDisposal extends Model
{
    protected $fillable = [
        'source_sales_actual_item_id',
        'disposed_at',
        'qty',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'disposed_at' => 'date',
        'qty' => 'decimal:2',
    ];

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SalesActualItem::class, 'source_sales_actual_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
