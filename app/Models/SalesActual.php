<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesActual extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ARCHIVED = 'archived';

    /** V2 berhenti 31 Okt 2026; sejak 1 Nov semua penjualan dicatat di V3. */
    public const V3_START = '2026-11-01';

    protected $fillable = [
        'delivery_order_id',
        'sales_date',
        'customer_id',
        'status',
        'is_internal',
        'submitted_at',
        'submitted_by',
        'sales_daily_closing_id',
        'notes',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'sales_date' => 'date',
        'submitted_at' => 'datetime',
    ];

    /**
     * Bukan penjualan ke customer sungguhan: Sales Actual internal hanya pembawa
     * Barang Sisa dari Rencana Masak (lihat ReadyStockService::closeDay).
     */
    public function scopeExternal(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesActualItem::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function dailyClosing(): BelongsTo
    {
        return $this->belongsTo(SalesDailyClosing::class, 'sales_daily_closing_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Draft V2 (bertanggal sebelum V3_START) yang dinonaktifkan: tidak bisa
     * diedit, disubmit, atau menampung Barang Sisa, dan tidak masuk laporan.
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? ucfirst((string) $this->status);
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_ARCHIVED => 'Nonaktif',
        ];
    }
}
