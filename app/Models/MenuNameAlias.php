<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Revisi v3.1: nama menu Excel rencana masak -> produk Master Menu (dipilih manual sekali). */
class MenuNameAlias extends Model
{
    protected $fillable = ['name_norm', 'price', 'product_id', 'created_by'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
