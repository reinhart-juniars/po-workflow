<?php

namespace App\Models;

use App\Helpers\AutoNumberHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tagihan Pembelian: nota belanja gudang yang ditagihkan ke accounting.
 *
 * Alur: draft (gudang melengkapi nota) -> diajukan -> dibayar | hutang
 * (supplier memberi tempo; dibayar belakangan dari halaman yang sama).
 * Accounting juga bisa mengembalikan tagihan ke gudang dengan alasan.
 *
 * Pembukuannya lewat PurchaseBillService; lihat penjelasan di sana.
 */
class PurchaseBill extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'diajukan';

    public const STATUS_RETURNED = 'dikembalikan';

    public const STATUS_CREDIT = 'hutang';

    public const STATUS_PAID = 'dibayar';

    protected $fillable = [
        'number',
        'status',
        'bill_date',
        'requisition_id',
        'supplier_id',
        'supplier_name',
        'total',
        'receipt_path',
        'notes',
        'payable_id',
        'cash_out_id',
        'cash_account_id',
        'due_date',
        'paid_on',
        'return_reason',
        'created_by',
        'submitted_by',
        'submitted_at',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'paid_on' => 'date',
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $bill) {
            if (blank($bill->number)) {
                $bill->number = AutoNumberHelper::generate('purchase_bills', 'number', 'TGH');
            }
        });
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Menunggu accounting',
            self::STATUS_RETURNED => 'Dikembalikan',
            self::STATUS_CREDIT => 'Hutang supplier',
            self::STATUS_PAID => 'Dibayar',
        ];
    }

    /** Warna badge per status (Filament & Blade .badge-soft-*). */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_SUBMITTED => 'warning',
            self::STATUS_RETURNED => 'danger',
            self::STATUS_CREDIT => 'info',
            self::STATUS_PAID => 'success',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    /** Gudang masih boleh melengkapi & mengajukan. */
    public function isEditableByInventory(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED], true);
    }

    /** Accounting bisa membayar (langsung, atau hutang supplier yang jatuh tempo). */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_CREDIT], true);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class)->orderBy('id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function cashOut(): BelongsTo
    {
        return $this->belongsTo(CashOut::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Antrean accounting: diajukan + hutang supplier yang belum dibayar. */
    public function scopeAwaitingAccounting(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_CREDIT]);
    }

    public function displaySupplier(): string
    {
        return $this->supplier?->name ?? ($this->supplier_name ?: 'Tanpa supplier');
    }
}
