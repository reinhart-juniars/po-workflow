<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceSheetAdjustment extends Model
{
    public const GROUP_CASH = 'cash';
    public const GROUP_RECEIVABLE = 'receivable';
    public const GROUP_INVENTORY = 'inventory';
    public const GROUP_FIXED_ASSET = 'fixed_asset';
    public const GROUP_PAYABLE = 'payable';
    public const GROUP_EQUITY = 'equity';
    public const GROUP_WEALTH = 'wealth';

    public const GROUPS = [
        self::GROUP_CASH,
        self::GROUP_RECEIVABLE,
        self::GROUP_INVENTORY,
        self::GROUP_FIXED_ASSET,
        self::GROUP_PAYABLE,
        self::GROUP_EQUITY,
        self::GROUP_WEALTH,
    ];

    protected $fillable = [
        'adjustment_date',
        'account_group',
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
}
