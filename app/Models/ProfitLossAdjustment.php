<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitLossAdjustment extends Model
{
    public const GROUP_REVENUE = 'revenue';
    public const GROUP_COGS = 'cogs';
    public const GROUP_OPERATING_EXPENSE = 'operating_expense';
    public const GROUP_OTHER_INCOME = 'other_income';

    public const GROUPS = [
        self::GROUP_REVENUE,
        self::GROUP_COGS,
        self::GROUP_OPERATING_EXPENSE,
        self::GROUP_OTHER_INCOME,
    ];

    protected $fillable = [
        'adjustment_date',
        'statement_group',
        'expense_category_id',
        'label',
        'amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }
}
