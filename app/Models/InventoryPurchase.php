<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPurchase extends Model
{
    /** Barang datang dalam kondisi baik dan menambah stok tersedia. */
    public const CONDITION_GOOD = 'good';

    /** Barang datang rusak/tidak layak: uangnya sudah keluar, tapi stok tidak bertambah. */
    public const CONDITION_DAMAGED = 'damaged';

    protected static function booted(): void
    {
        static::saving(function (self $inventoryPurchase) {
            $inventoryPurchase->total_value = (float) $inventoryPurchase->qty * (float) $inventoryPurchase->unit_cost;
        });
    }

    protected $fillable = [
        'inventory_item_id',
        'requisition_id',
        'transaction_date',
        'qty',
        'unit_cost',
        'total_value',
        'payment_type',
        'cash_out_id',
        'payable_id',
        'condition',
        'condition_notes',
        'condition_checked_at',
        'condition_checked_by',
        'supplier_name',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'qty' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'condition_checked_at' => 'datetime',
    ];

    /** Form kebutuhan yang menjadi alasan pembelian ini, bila ada. */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public static function conditionOptions(): array
    {
        return [
            self::CONDITION_GOOD => 'Baik',
            self::CONDITION_DAMAGED => 'Tidak Baik',
        ];
    }

    public function conditionLabel(): string
    {
        return self::conditionOptions()[$this->condition] ?? (string) $this->condition;
    }

    public function isDamaged(): bool
    {
        return $this->condition === self::CONDITION_DAMAGED;
    }

    /**
     * Pembelian yang benar-benar menambah stok tersedia.
     *
     * Satu-satunya definisi "barang masuk" di aplikasi ini. Laporan pemakaian
     * bahan, neraca, dan laba rugi wajib memakai scope ini -- ketiganya dulu
     * menjumlahkan inventory_purchases dengan query masing-masing, dan
     * duplikasi seperti itulah yang membuat satu tempat gampang tertinggal
     * saat aturannya berubah.
     */
    public function scopeAddsToStock(Builder $query): Builder
    {
        return $query->where('condition', self::CONDITION_GOOD);
    }

    /** Kebalikannya: barang yang datang rusak, dilaporkan sebagai kerugian. */
    public function scopeDamaged(Builder $query): Builder
    {
        return $query->where('condition', self::CONDITION_DAMAGED);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function cashOut(): BelongsTo
    {
        return $this->belongsTo(CashOut::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }
}
