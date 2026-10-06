<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu komponen hasil rincian Barang Sisa (mis. "Telur ceplok, 3 butir"). */
class LeftoverComponent extends Model
{
    protected $fillable = [
        'leftover_breakdown_id',
        'name',
        'unit',
        'qty',
        'value',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'value' => 'decimal:2',
    ];

    public function breakdown(): BelongsTo
    {
        return $this->belongsTo(LeftoverBreakdown::class, 'leftover_breakdown_id');
    }

    /** Penjualan Barang Sisa yang mengambil komponen ini (draft maupun submitted). */
    public function sales(): HasMany
    {
        return $this->hasMany(SalesActualItem::class);
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(LeftoverDisposal::class);
    }

    public function unitCost(): float
    {
        return (float) $this->qty > 0 ? (float) $this->value / (float) $this->qty : 0.0;
    }
}
