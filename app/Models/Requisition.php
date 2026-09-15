<?php

namespace App\Models;

use App\Helpers\AutoNumberHelper;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Form Kebutuhan, Stok & Pembelian Barang untuk satu SPK Produksi.
 *
 * Alur persetujuan: draft -> approved -> checked. Lihat migration requisitions.
 */
class Requisition extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CHECKED = 'checked';

    protected $fillable = [
        'number',
        'production_order_id',
        'status',
        'prepared_by',
        'prepared_at',
        'approved_by',
        'approved_at',
        'checked_by',
        'checked_at',
        'notes',
        'payment_type',
        'expense_category_id',
        'cash_account_id',
        'supplier_name',
        'due_date',
    ];

    protected $casts = [
        'prepared_at' => 'datetime',
        'approved_at' => 'datetime',
        'checked_at' => 'datetime',
        'due_date' => 'date',
    ];

    /** @return array<string, string> */
    public static function paymentTypeOptions(): array
    {
        return ['cash' => 'Tunai', 'payable' => 'Kredit (Hutang)'];
    }

    public function paymentTypeLabel(): ?string
    {
        return self::paymentTypeOptions()[$this->payment_type] ?? null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $requisition) {
            if (empty($requisition->number)) {
                $requisition->number = AutoNumberHelper::generate('requisitions', 'number', app(Settings::class)->get('document.requisition_prefix'));
            }

            if (empty($requisition->status)) {
                $requisition->status = self::STATUS_DRAFT;
            }
        });
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Dibuat / Diisi',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_CHECKED => 'Diperiksa',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isChecked(): bool
    {
        return $this->status === self::STATUS_CHECKED;
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Pembelian bahan baku yang ditautkan ke form ini. */
    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
