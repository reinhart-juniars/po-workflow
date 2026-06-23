<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningInventory extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $openingInventory) {
            $openingInventory->total_value = (float) $openingInventory->qty * (float) $openingInventory->unit_cost;
        });
    }

    protected $fillable = [
        'balance_date',
        'item_name',
        'qty',
        'unit',
        'unit_cost',
        'total_value',
        'notes',
        'is_adjustment',
        'adjustment_note',
        'adjusted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'balance_date' => 'date',
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'is_adjustment' => 'boolean',
    ];

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
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
