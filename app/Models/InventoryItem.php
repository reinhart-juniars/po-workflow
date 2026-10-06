<?php

namespace App\Models;

use App\Support\Units\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** Supplier yang memasok bahan ini. */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)->withTimestamps();
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

    /**
     * Sumber perubahan harga yang sedang berjalan ('panel', 'import', ...);
     * null berarti histori tidak dicatat -- dipakai migrasi Master Menu yang
     * membawa historinya sendiri.
     */
    public static ?string $priceChangeSource = 'panel';

    /** Jalankan $callback tanpa mencatat histori harga (mis. migrasi). */
    public static function withoutPriceHistory(callable $callback): mixed
    {
        $previous = static::$priceChangeSource;
        static::$priceChangeSource = null;

        try {
            return $callback();
        } finally {
            static::$priceChangeSource = $previous;
        }
    }

    /**
     * Setiap perubahan harga satuan/kemasan dicatat sebagai histori, seperti
     * yang dilakukan Master Menu -- inilah yang nanti memberi makan notifikasi
     * perubahan harga (Bagian B) dan menjelaskan lonjakan HPP.
     */
    protected static function booted(): void
    {
        // Hanya bila kolomnya ikut dimuat: model yang diambil sebagian
        // kolom tidak boleh menulis NULL ke unit.
        static::saving(function (self $item) {
            if ($item->isDirty('unit')) {
                $item->unit = Unit::canonical($item->unit);
            }
        });

        // Dipisah created/updated: wasRecentlyCreated tetap true seumur
        // instance, jadi tidak bisa dipakai membedakan keduanya di saved().
        static::created(function (self $item) {
            if (static::$priceChangeSource === null || ($item->unit_price === null && $item->pack_price === null)) {
                return;
            }

            $item->recordPriceHistory(null, null, InventoryItemPriceHistory::ACTION_SET_AWAL);
        });

        static::updated(function (self $item) {
            if (static::$priceChangeSource === null || (! $item->wasChanged('unit_price') && ! $item->wasChanged('pack_price'))) {
                return;
            }

            $oldUnit = $item->getOriginal('unit_price');
            $newUnit = $item->unit_price;

            $action = match (true) {
                $oldUnit === null || (float) $oldUnit == 0.0 => InventoryItemPriceHistory::ACTION_SET_AWAL,
                (float) $newUnit > (float) $oldUnit => InventoryItemPriceHistory::ACTION_NAIK,
                (float) $newUnit < (float) $oldUnit => InventoryItemPriceHistory::ACTION_TURUN_DIPAKSA,
                default => InventoryItemPriceHistory::ACTION_NAIK, // hanya harga kemasan yang berubah
            };

            $item->recordPriceHistory($oldUnit, $item->getOriginal('pack_price'), $action);

            // Bagian B.1: lonceng perubahan harga beli bahan, di atas histori.
            app(\App\Services\PriceChangeNotifier::class)->ingredientChanged(
                $item,
                $oldUnit === null ? null : (float) $oldUnit,
                $newUnit === null ? null : (float) $newUnit,
                static::$priceChangeSource,
            );
        });
    }

    protected function recordPriceHistory(mixed $oldUnit, mixed $oldPack, string $action): void
    {
        $this->priceHistories()->create([
            'old_unit_price' => $oldUnit,
            'new_unit_price' => $this->unit_price,
            'old_pack_price' => $oldPack,
            'new_pack_price' => $this->pack_price,
            'action' => $action,
            'source' => static::$priceChangeSource,
            'created_by' => auth()->id(),
        ]);
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
