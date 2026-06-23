<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAccountTransfer extends Model
{
    protected $fillable = [
        'transfer_date',
        'from_cash_account_id',
        'to_cash_account_id',
        'amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function fromCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'from_cash_account_id');
    }

    public function toCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'to_cash_account_id');
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
