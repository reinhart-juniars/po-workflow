<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesActual extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_order_id',
        'sales_date',
        'customer_id',
        'status',
        'submitted_at',
        'submitted_by',
        'sales_daily_closing_id',
        'notes',
    ];

    protected $casts = [
        'sales_date' => 'date',
        'submitted_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesActualItem::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function dailyClosing(): BelongsTo
    {
        return $this->belongsTo(SalesDailyClosing::class, 'sales_daily_closing_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
