<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpeningBalance extends Model
{
    protected $fillable = [
        'balance_date',
        'type',
        'reference_id',
        'supplier_name',
        'amount',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'balance_date' => 'date',
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

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'reference_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'reference_id');
    }

    public function payable(): HasOne
    {
        return $this->hasOne(Payable::class);
    }
}
