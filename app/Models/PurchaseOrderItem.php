<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model {
    use HasFactory;
    protected $fillable=
    [
        'purchase_order_id','product_id','is_custom','custom_name','qty','unit',
        'unit_price','raw_material_cost','overhead_cost','subtotal','notes'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'raw_material_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            // Snapshot harga & cost dari produk HANYA untuk item baru.
            // Item yang sudah ada tidak boleh ter-refresh oleh perubahan harga master.
            if (! $item->exists && $item->product_id) {
                $product = $item->relationLoaded('product')
                    ? $item->product
                    : Product::find($item->product_id);

                if ($product) {
                    if (empty($item->unit_price)) {
                        $item->unit_price = (float) ($product->base_price ?? 0);
                    }
                    if ($item->raw_material_cost === null) {
                        $item->raw_material_cost = $product->raw_material_cost;
                    }
                    if ($item->overhead_cost === null) {
                        $item->overhead_cost = $product->overhead_cost;
                    }
                }
            }

            $item->qty = max(0, (int) ($item->qty ?? 0));
            $item->unit_price = max(0, (float) ($item->unit_price ?? 0));
            $disc = (float) ($item->discount_percent ?? 0);
            $item->discount_percent = min(100, max(0, $disc));
            $item->subtotal = round($item->qty * $item->unit_price * (1 - $item->discount_percent/100), 2);
        });

        static::saved(function (self $item) {
            $item->purchaseOrder?->recalcTotals();
        });

        static::deleted(function (self $item) {
            $item->purchaseOrder?->recalcTotals();
        });
    }
    public function purchaseOrder()
    {
        return $this->belongsTo(\App\Models\PurchaseOrder::class);
    }
    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class);
    }
    public function salesActualItems()
    {
        return $this->hasMany(\App\Models\SalesActualItem::class);
    }
}
