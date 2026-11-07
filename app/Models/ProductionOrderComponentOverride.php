<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isian yang hanya berlaku untuk satu SPK Produksi: jumlah khusus sebuah komponen
 * (mis. 12,5 porsi dimasak 13) dan catatan di kolom Pelaksana.
 */
class ProductionOrderComponentOverride extends Model
{
    protected $fillable = [
        'production_order_id',
        'recipe_id',
        'component_key',
        'qty',
        'note',
        'updated_by',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
