<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'base_price',
        'raw_material_cost',
        'overhead_cost',
        'profit',
        'changed_by',
        'reason',
        'effective_from',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'raw_material_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'profit' => 'decimal:2',
        'effective_from' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
