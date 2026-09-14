<?php

namespace App\Models;

use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionLine extends Model
{
    protected $fillable = [
        'requisition_id',
        'sort_order',
        'inventory_item_id',
        'name',
        'unit',
        'required_qty',
        'opening_stock_qty',
        'purchase_qty',
        'unit_price',
        'actual_used_qty',
        'remaining_qty',
        'notes',
    ];

    protected $casts = [
        'required_qty' => 'decimal:4',
        'opening_stock_qty' => 'decimal:4',
        'purchase_qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'actual_used_qty' => 'decimal:4',
        'remaining_qty' => 'decimal:4',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
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
