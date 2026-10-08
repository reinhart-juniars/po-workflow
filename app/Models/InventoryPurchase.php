<?php

namespace App\Models;

use App\Models\Concerns\LinksSupplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPurchase extends Model
{
    use LinksSupplier;

    /** Barang datang dalam kondisi baik dan menambah stok tersedia. */
    public const CONDITION_GOOD = 'good';

    /** Barang datang rusak/tidak layak: uangnya sudah keluar, tapi stok tidak bertambah. */
    public const CONDITION_DAMAGED = 'damaged';

    /**
     * Jenis bayar: tunai (kas keluar sendiri), kredit (hutang sendiri), atau
     * tagihan -- kas/hutangnya dipegang Tagihan Pembelian (satu per nota).
     */
    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_PAYABLE = 'payable';

    public const PAYMENT_BILL = 'bill';

    protected static function booted(): void
    {
        static::saving(function (self $inventoryPurchase) {
            // Nilai mengikuti qty x harga satuan, kecuali nilainya sendiri yang
            // diisi eksplisit: harga per gram (4 desimal) dibulatkan ke 2 desimal
            // di unit_cost, jadi qty x unit_cost bisa kehilangan rupiah.
            if (! $inventoryPurchase->isDirty('total_value') || $inventoryPurchase->total_value === null) {
                $inventoryPurchase->total_value = (float) $inventoryPurchase->qty * (float) $inventoryPurchase->unit_cost;
            }
        });

        // Bahan yang dibeli dari supplier otomatis tercatat sebagai bahan
        // yang dipasoknya, supaya daftar di Master Supplier ikut hidup.
        static::saved(function (self $inventoryPurchase) {
            if (! $inventoryPurchase->supplier_id || ! $inventoryPurchase->inventory_item_id) {
                return;
            }

            if ($inventoryPurchase->wasRecentlyCreated || $inventoryPurchase->wasChanged(['supplier_id', 'inventory_item_id'])) {
                $inventoryPurchase->supplier?->items()->syncWithoutDetaching([$inventoryPurchase->inventory_item_id]);
            }
        });
    }

    protected $fillable = [
        'inventory_item_id',
        'requisition_id',
        'purchase_bill_id',
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
        'supplier_id',
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

    /** Tagihan Pembelian yang menagihkan pembelian ini ke accounting. */
    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    public static function conditionOptions(): array
    {
        return [
            self::CONDITION_GOOD => 'Baik',
            self::CONDITION_DAMAGED => 'Tidak Baik',
        ];
    }

    /** @return array<string, string> */
    public static function paymentTypeOptions(): array
    {
        return [
            self::PAYMENT_CASH => 'Tunai',
            self::PAYMENT_PAYABLE => 'Kredit',
            self::PAYMENT_BILL => 'Tagihan',
        ];
    }

    public function paymentTypeLabel(): string
    {
        return self::paymentTypeOptions()[$this->payment_type] ?? (string) $this->payment_type;
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
