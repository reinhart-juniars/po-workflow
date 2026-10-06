<?php

namespace App\Models;

use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionLine extends Model
{
    /** Barang ditolak dikembalikan ke supplier / tidak dibayar. */
    public const REJECT_RETURN = 'retur';

    /** Barang ditolak tetap dibayar: nilainya masuk Kerugian Barang Rusak. */
    public const REJECT_PAID = 'dibayar';

    protected $fillable = [
        'requisition_id',
        'sort_order',
        'inventory_item_id',
        'name',
        'unit',
        'required_qty',
        'opening_stock_qty',
        'purchase_qty',
        'received_qty',
        'rejected_qty',
        'rejected_reason',
        'rejected_treatment',
        'unit_price',
        'purchase_price',
        'inventory_purchase_id',
        'damaged_purchase_id',
        'actual_used_qty',
        'remaining_qty',
        'notes',
    ];

    protected $casts = [
        'required_qty' => 'decimal:4',
        'opening_stock_qty' => 'decimal:4',
        'purchase_qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
        'rejected_qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'purchase_price' => 'decimal:4',
        'actual_used_qty' => 'decimal:4',
        'remaining_qty' => 'decimal:4',
    ];

    /** @return array<string, string> */
    public static function rejectTreatmentOptions(): array
    {
        return [
            self::REJECT_RETURN => 'Retur / tidak dibayar',
            self::REJECT_PAID => 'Dibayar (kerugian barang rusak)',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** Pembelian bahan baku (kondisi Baik) yang dibuat otomatis saat Periksa. */
    public function inventoryPurchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class, 'inventory_purchase_id');
    }

    /** Pembelian berkondisi Tidak Baik untuk barang ditolak yang tetap dibayar. */
    public function damagedPurchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class, 'damaged_purchase_id');
    }

    /**
     * Beli yang disarankan: Kebutuhan - Stok Awal, tidak pernah negatif.
     *
     * Ini rumus di form kertas. Nilai tersimpannya (purchase_qty) boleh berbeda
     * karena disunting manusia, mis. dibulatkan ke kemasan.
     */
    public function suggestedPurchaseQty(): float
    {
        $required = (float) $this->required_qty;
        $opening = $this->opening_stock_qty === null ? 0.0 : (float) $this->opening_stock_qty;
        $shortfall = round(max($required - $opening, 0), 4);

        // Pembelian biasanya per kemasan utuh: 0,3 dus tetap harus beli 1 dus.
        return app(Settings::class)->bool('requisition.round_purchase_up') ? (float) ceil($shortfall) : $shortfall;
    }

    /**
     * Yang masuk ledger saat Periksa: jumlah diterima bila dicatat (barang
     * datang rusak dikurangi di sini), selain itu sejumlah Beli.
     */
    public function receivedQty(): float
    {
        return $this->received_qty === null ? (float) $this->purchase_qty : (float) $this->received_qty;
    }

    /** Yang ditolak saat barang datang = Beli - Diterima (tidak pernah negatif). */
    public function rejectedQty(): float
    {
        return round(max((float) $this->purchase_qty - $this->receivedQty(), 0), 4);
    }

    /** Harga yang dipakai kartu stok & pembelian: harga beli aktual bila dicatat, selain itu harga master. */
    public function purchasePrice(): ?float
    {
        if ($this->purchase_price !== null) {
            return (float) $this->purchase_price;
        }

        return $this->unit_price === null ? null : (float) $this->unit_price;
    }

    /** Nilai pembelian yang masuk stok: diterima x harga beli. */
    public function purchaseValue(): float
    {
        return round($this->receivedQty() * (float) ($this->purchasePrice() ?? 0), 2);
    }

    /** Nilai barang ditolak yang tetap dibayar (kerugian); nol bila diretur. */
    public function damagedValue(): float
    {
        if ($this->rejected_treatment !== self::REJECT_PAID) {
            return 0.0;
        }

        return round($this->rejectedQty() * (float) ($this->purchasePrice() ?? 0), 2);
    }

    /** Pemakaian yang diposting ke ledger: aktual bila diisi, kebutuhan bila tidak. */
    public function usageQty(): float
    {
        return $this->actual_used_qty === null
            ? (float) $this->required_qty
            : (float) $this->actual_used_qty;
    }

    public function usageValue(): float
    {
        return round($this->usageQty() * (float) ($this->unit_price ?? 0), 2);
    }
}
