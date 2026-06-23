<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryOpening extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $inventoryOpening) {
            $inventoryOpening->total_value = (float) $inventoryOpening->qty * (float) $inventoryOpening->unit_cost;
        });
    }

    protected $fillable = [
        'inventory_item_id',
        'balance_date',
        'qty',
        'unit_cost',
        'total_value',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'balance_date' => 'date',
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
