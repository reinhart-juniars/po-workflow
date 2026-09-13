<?php

namespace App\Models;

use App\Support\Units\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    public const CATEGORY_FIXED_ASSET = 'inventaris';

    public const CATEGORY_RAW_MATERIAL = 'bahan_baku';

    public const CATEGORY_PACKAGING = 'packaging';

    protected $fillable = [
        'name',
        'unit',
        'parent_id',
        'category',
        'ingredient_group',
        'pack_qty',
        'pack_price',
        'unit_price',
        'is_prepared',
        'source_ingredient_id',
        'minimum_stock_value',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'minimum_stock_value' => 'decimal:2',
        'pack_qty' => 'decimal:4',
        'pack_price' => 'decimal:2',
        'unit_price' => 'decimal:4',
        'is_prepared' => 'boolean',
    ];

    /** Item ini ikut dipantau alert stok minimum. */
    public function hasStockAlert(): bool
    {
        return $this->minimum_stock_value !== null;
    }

    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_FIXED_ASSET => 'Inventaris',
            self::CATEGORY_RAW_MATERIAL => 'Bahan Baku',
            self::CATEGORY_PACKAGING => 'Kemasan',
        ];
    }

    public static function stockCategories(): array
    {
        return [
            self::CATEGORY_RAW_MATERIAL,
            self::CATEGORY_PACKAGING,
        ];
    }

    public function categoryLabel(): string
    {
        return self::categoryOptions()[$this->category] ?? ucfirst(str_replace('_', ' ', (string) $this->category));
    }

    public function openings(): HasMany
    {
        return $this->hasMany(InventoryOpening::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function opnames(): HasMany
    {
        return $this->hasMany(StockOpname::class);
    }

    /**
     * Bucket induk item ini.
     *
     * Tiga bucket lama (Bahan Baku, Inventaris, Packaging) tetap memegang
     * seluruh histori pembelian dan opname, dan tetap menjadi sumber angka Laba
     * Rugi. Bahan hasil migrasi Master Menu bergabung sebagai anak di bawahnya.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(InventoryItemPriceHistory::class)->latest();
    }

    /** Baris resep yang memakai bahan ini. */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Ledger kuantitas bahan ini. */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Item ini sendiri sebuah bucket, bukan bahan detail. */
    public function isBucket(): bool
    {
        return $this->parent_id === null;
    }

    /** Satuan item ini, bila dikenali registri satuan. */
    public function unitEnum(): ?Unit
    {
        return Unit::tryFromAlias($this->unit);
    }

    /**
     * Harga per satuan, dihitung dari kemasan bila belum tersimpan.
     *
     * pack_price / pack_qty dipakai sebagai cadangan supaya bahan yang baru
     * diisi harga kemasannya tetap bisa menghitung HPP tanpa langkah tambahan.
     */
    public function effectiveUnitPrice(): ?float
    {
        if ($this->unit_price !== null) {
            return (float) $this->unit_price;
        }

        if ($this->pack_price !== null && (float) $this->pack_qty > 0) {
            return round((float) $this->pack_price / (float) $this->pack_qty, 4);
        }

        return null;
    }

    /** Bahan detail yang siap dipakai menghitung HPP. */
    public function scopeIngredients(Builder $query): Builder
    {
        return $query->whereNotNull('parent_id');
    }

    public function scopeBuckets(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
