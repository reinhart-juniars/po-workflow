<?php

namespace App\Models;

use App\Models\Concerns\LinksSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payable extends Model
{
    use LinksSupplier;

    protected $fillable = [
        'transaction_date',
        'opening_balance_id',
        'due_date',
        'supplier_name',
        'supplier_id',
        'description',
        'amount',
        'status',
        'paid_at',
        'notes',
        'is_adjustment',
        'adjustment_note',
        'adjusted_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'is_adjustment' => 'boolean',
    ];

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(OpeningBalance::class);
    }

    public function inventoryPurchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function cashOuts(): HasMany
    {
        return $this->hasMany(CashOut::class);
    }

    /** Tagihan Pembelian pemilik hutang ini, bila lahir dari belanja gudang. */
    public function purchaseBill(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PurchaseBill::class);
    }

    /**
     * Hutang yang boleh dilunasi lewat Pengeluaran › Pembayaran Kredit.
     * Hutang milik Tagihan Pembelian hanya dibayar dari halaman Tagihan --
     * satu pintu, supaya tidak terbayar dua kali dan status tagihannya ikut.
     */
    public function scopeSettleableByCredit(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereDoesntHave('purchaseBill');
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
