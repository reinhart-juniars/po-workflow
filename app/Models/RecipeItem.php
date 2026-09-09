<?php

namespace App\Models;

use App\Support\Units\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    protected $fillable = [
        'recipe_id',
        'sort_order',
        'section',
        'inventory_item_id',
        'ref_recipe_id',
        'raw_name',
        'qty',
        'unit',
        'unit_price_snapshot',
        'notes',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price_snapshot' => 'decimal:4',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function refRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'ref_recipe_id');
    }

    /** Satuan baris ini, bila dikenali registri satuan. */
    public function unitEnum(): ?Unit
    {
        return Unit::tryFromAlias($this->unit);
    }

    /** Baris yang belum menunjuk bahan maupun sub-resep. */
    public function isUnmatched(): bool
    {
        return $this->inventory_item_id === null && $this->ref_recipe_id === null;
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('inventory_item_id')->whereNull('ref_recipe_id');
    }
}
