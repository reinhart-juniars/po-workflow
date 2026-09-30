<?php

namespace App\Models;

use App\Services\InventoryLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu gerakan stok pada ledger kuantitas per bahan.
 *
 * qty bertanda: masuk positif, keluar negatif. Lihat migration inventory_movements.
 */
class InventoryMovement extends Model
{
    public const TYPE_OPENING = 'opening';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_USAGE = 'usage';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'inventory_item_id',
        'moved_at',
        'type',
        'qty',
        'unit',
        'unit_price',
        'total_value',
        'production_order_id',
        'requisition_line_id',
        'inventory_purchase_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'moved_at' => 'date',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'total_value' => 'decimal:2',
    ];

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_OPENING => 'Saldo Awal',
            self::TYPE_PURCHASE => 'Pembelian',
            self::TYPE_USAGE => 'Pemakaian Produksi',
            self::TYPE_ADJUSTMENT => 'Penyesuaian (Hilang/Temuan)',
        ];
    }

    /**
     * Label jenis untuk satu baris. Penyesuaian dibedakan dari tandanya:
     * hitung fisik lebih sedikit dari saldo = Barang Hilang, lebih banyak =
     * Barang Temuan -- istilah yang dipakai Laba Rugi.
     */
    public function typeLabel(): string
    {
        if ($this->type === self::TYPE_ADJUSTMENT) {
            return (float) $this->qty < 0 ? 'Barang Hilang' : 'Barang Temuan';
        }

        return self::typeLabels()[$this->type] ?? (string) $this->type;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(RequisitionLine::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Rentang inklusif per hari yang memakai index (lihat InventoryLedgerService::day()). */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where('moved_at', '>=', InventoryLedgerService::day($from))
            ->where('moved_at', '<', InventoryLedgerService::dayAfter($to));
    }
}
