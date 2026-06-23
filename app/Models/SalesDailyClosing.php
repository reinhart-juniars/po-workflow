<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDailyClosing extends Model
{
    protected $fillable = [
        'closing_date',
        'cash_in_date',
        'gross_amount',
        'discount_amount',
        'net_amount',
        'notes',
        'posted_at',
        'posted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'closing_date' => 'date',
        'cash_in_date' => 'date',
        'gross_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function salesActuals(): HasMany
    {
        return $this->hasMany(SalesActual::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
