<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesActualItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_actual_id',
        'purchase_order_item_id',
        'product_id',
        'item_name',
        'unit',
        'qty_delivery',
        'qty_actual',
        'qty_return',
        'qty_waste',
        'qty_cancel',
        'unit_price',
        'raw_material_cost',
        'overhead_cost',
        'subtotal_actual',
        'is_carry_forward',
        'source_sales_actual_item_id',
        'notes',
    ];

    protected $casts = [
        'qty_delivery' => 'decimal:2',
        'qty_actual' => 'decimal:2',
        'qty_return' => 'decimal:2',
        'qty_waste' => 'decimal:2',
        'qty_cancel' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'raw_material_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'subtotal_actual' => 'decimal:2',
        'is_carry_forward' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            // Snapshot cost dari sumbernya HANYA untuk item baru.
            // Setelah tersimpan, perubahan harga/cost master tidak boleh memengaruhi item ini.
            if (! $item->exists) {
                if ($item->raw_material_cost === null || $item->overhead_cost === null) {
                    $source = null;

                    if ($item->purchase_order_item_id) {
                        $source = PurchaseOrderItem::find($item->purchase_order_item_id);
                    }

                    if ($source) {
                        if ($item->raw_material_cost === null) {
                            $item->raw_material_cost = $source->raw_material_cost;
                        }
                        if ($item->overhead_cost === null) {
                            $item->overhead_cost = $source->overhead_cost;
                        }
                    } elseif ($item->product_id) {
                        $product = Product::find($item->product_id);
                        if ($product) {
                            if ($item->raw_material_cost === null) {
                                $item->raw_material_cost = $product->raw_material_cost;
                            }
                            if ($item->overhead_cost === null) {
                                $item->overhead_cost = $product->overhead_cost;
                            }
                        }
                    }
                }
            }

            foreach (['qty_delivery', 'qty_actual', 'qty_waste', 'qty_cancel', 'unit_price'] as $field) {
                $item->{$field} = max(0, round((float) ($item->{$field} ?? 0), 2));
            }

            // Waste hanya relevan untuk item carry forward (barang retur kemarin
            // yang baru ketahuan tidak layak jual). Item lain selalu 0.
            if (! $item->is_carry_forward) {
                $item->qty_waste = 0;
            }

            $item->qty_cancel = 0;
            // Sisa yang dibawa ke draft berikutnya = qty delivery dikurangi yang terjual
            // dan yang dibuang (waste). Waste tidak ikut carry forward.
            $item->qty_return = max(0, round((float) $item->qty_delivery - (float) $item->qty_actual - (float) $item->qty_waste, 2));
            $item->subtotal_actual = round((float) $item->qty_actual * (float) $item->unit_price, 2);
        });
    }

    public function salesActual(): BelongsTo
    {
        return $this->belongsTo(SalesActual::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function sourceSalesActualItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_sales_actual_item_id');
    }

    public function carryForwardItem(): HasOne
    {
        return $this->hasOne(self::class, 'source_sales_actual_item_id');
    }
}
