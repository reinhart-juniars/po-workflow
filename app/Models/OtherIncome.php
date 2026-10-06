<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtherIncome extends Model
{
    public const SOURCE_SALES_ACTUAL = SalesActual::class;
    public const SOURCE_SALES_DAILY_CLOSING = SalesDailyClosing::class;
    public const SOURCE_PURCHASE_ORDER_SHIPPING = PurchaseOrder::class;

    public const CATEGORY_SALES_ACTUAL = 'Sales Actual';
    public const CATEGORY_OTHER_SALES = 'Penjualan Lain-Lain';

    protected $fillable = [
        'income_date',
        'income_category_id',
        'cash_account_id',
        'source_type',
        'source_id',
        'amount',
        'description',
        'is_adjustment',
        'adjustment_note',
        'adjusted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'income_date' => 'date',
        'amount' => 'decimal:2',
        'is_adjustment' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class, 'income_category_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isGeneratedBySalesActual(): bool
    {
        // Only "Sales Actual" category is the double-counted revenue:
        // laba rugi sums sales_actual_items.subtotal_actual directly, so the OtherIncome twin
        // must be excluded. Shipping OtherIncome (Penjualan Lain-Lain) is genuine other revenue
        // and should remain visible in laba rugi / cashflow.
        $categoryName = trim((string) ($this->category?->name ?? ''));

        if (strcasecmp($categoryName, self::CATEGORY_SALES_ACTUAL) !== 0) {
            return false;
        }

        if (in_array($this->source_type, [self::SOURCE_SALES_ACTUAL, self::SOURCE_SALES_DAILY_CLOSING], true) && filled($this->source_id)) {
            return true;
        }

        return preg_match('/^Sales Actual #\d+(?:\b|$)/i', trim((string) $this->description)) === 1;
    }
}
