<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Revisi v3.1: stok siap satu tanggal produksi ditutup; sisanya jadi Barang Sisa. */
class ReadyStockClosing extends Model
{
    protected $fillable = ['production_date', 'sales_actual_id', 'leftover_qty', 'leftover_value', 'closed_by'];

    protected $casts = [
        'production_date' => 'date',
        'leftover_qty' => 'decimal:2',
        'leftover_value' => 'decimal:2',
    ];

    public function salesActual(): BelongsTo
    {
        return $this->belongsTo(SalesActual::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
