<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rincian Barang Sisa: sejumlah porsi retur dipecah menjadi komponen yang
 * diketik user. Porsinya keluar dari stok entri pada broken_at; selisih
 * portion_value dengan total nilai komponen dicatat sebagai waste.
 */
class LeftoverBreakdown extends Model
{
    protected $fillable = [
        'source_sales_actual_item_id',
        'broken_at',
        'portion_qty',
        'portion_value',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'broken_at' => 'date',
        'portion_qty' => 'decimal:2',
        'portion_value' => 'decimal:2',
    ];

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SalesActualItem::class, 'source_sales_actual_item_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(LeftoverComponent::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function componentsValue(): float
    {
        return round((float) $this->components->sum('value'), 2);
    }

    /** Nilai porsi yang tidak terinci ke komponen mana pun (waste). */
    public function unallocatedValue(): float
    {
        return round(max(0, (float) $this->portion_value - $this->componentsValue()), 2);
    }
}
