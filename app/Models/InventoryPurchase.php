<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPurchase extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $inventoryPurchase) {
            $inventoryPurchase->total_value = (float) $inventoryPurchase->qty * (float) $inventoryPurchase->unit_cost;
        });
    }

    protected $fillable = [
        'inventory_item_id',
        'transaction_date',
        'qty',
        'unit_cost',
        'total_value',
        'payment_type',
        'cash_out_id',
        'payable_id',
        'supplier_name',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function cashOut(): BelongsTo
    {
        return $this->belongsTo(CashOut::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }
}
