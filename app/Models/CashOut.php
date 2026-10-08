<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashOut extends Model
{
    protected $fillable = [
        'expense_category_id',
        'expense_location_id',
        'cash_account_id',
        'payable_id',
        'amount',
        'expense_date',
        'description',
        'is_adjustment',
        'adjustment_note',
        'adjusted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'is_adjustment' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Pengeluaran yang dihitung sebagai beban di Laba Rugi: kategori
     * Pengeluaran Langsung, ditambah kategori "Mengurangi Kekayaan" yang
     * tanggalnya SEBELUM mode itu berlaku (ExpenseCategory::wealthReductionStart)
     * -- dulu sudah dilaporkan sebagai beban, jadi tetap beban.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder): mixed|null  $category  Syarat kategori
     *                                                                                 tambahan titik pemanggil (mis. include_hpp = false).
     */
    public function scopeInProfitAndLoss(\Illuminate\Database\Eloquent\Builder $query, ?\Closure $category = null): \Illuminate\Database\Eloquent\Builder
    {
        $start = ExpenseCategory::wealthReductionStart();
        $withMode = fn (string $mode) => function ($categoryQuery) use ($mode, $category) {
            $categoryQuery->where('expense_mode', $mode);

            if ($category) {
                $category($categoryQuery);
            }
        };

        return $query->where(fn ($inner) => $inner
            ->whereHas('category', $withMode(ExpenseCategory::MODE_DIRECT_EXPENSE))
            ->orWhere(fn ($legacy) => $legacy
                ->whereDate('expense_date', '<', $start)
                ->whereHas('category', $withMode(ExpenseCategory::MODE_WEALTH_REDUCTION))));
    }

    /**
     * Pengeluaran "Mengurangi Kekayaan" yang sudah berlaku (sejak tanggal
     * mulai): di luar Laba Rugi, menjadi baris pengurang Kekayaan di Neraca.
     */
    public function scopeWealthReduction(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereDate('expense_date', '>=', ExpenseCategory::wealthReductionStart())
            ->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('expense_mode', ExpenseCategory::MODE_WEALTH_REDUCTION));
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ExpenseLocation::class, 'expense_location_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function inventoryPurchase(): HasOne
    {
        return $this->hasOne(InventoryPurchase::class);
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
}
