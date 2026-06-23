<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payable extends Model
{
    protected $fillable = [
        'transaction_date',
        'opening_balance_id',
        'due_date',
        'supplier_name',
        'description',
        'amount',
        'status',
        'paid_at',
        'notes',
        'is_adjustment',
        'adjustment_note',
        'adjusted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'is_adjustment' => 'boolean',
    ];

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(OpeningBalance::class);
    }

    public function inventoryPurchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function cashOuts(): HasMany
    {
        return $this->hasMany(CashOut::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
