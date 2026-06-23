<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    public const MODE_DIRECT_EXPENSE = 'direct_expense';
    public const MODE_INVENTORY_PURCHASE = 'inventory_purchase';
    public const MODE_FIXED_ASSET = 'fixed_asset';

    protected $fillable = [
        'name',
        'description',
        'expense_mode',
        'include_hpp',
        'is_active',
    ];

    protected $casts = [
        'include_hpp' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isDirectExpense(): bool
    {
        return $this->expense_mode === self::MODE_DIRECT_EXPENSE;
    }

    public function isInventoryPurchase(): bool
    {
        return $this->expense_mode === self::MODE_INVENTORY_PURCHASE;
    }

    public function isFixedAsset(): bool
    {
        return $this->expense_mode === self::MODE_FIXED_ASSET;
    }

    public function cashOuts(): HasMany
    {
        return $this->hasMany(CashOut::class);
    }
}
