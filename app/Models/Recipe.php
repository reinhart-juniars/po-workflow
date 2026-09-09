<?php

namespace App\Models;

use App\Support\Units\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    /** Menu yang dijual ke pelanggan. */
    public const JENIS_UTAMA = 'utama';

    /** Komponen yang dipakai di dalam resep lain, mis. bumbu atau lauk. */
    public const JENIS_SUB = 'sub';

    protected $fillable = [
        'name',
        'name_norm',
        'product_id',
        'jenis',
        'kategori',
        'yield_qty',
        'yield_unit',
        'ohc_pct',
        'profit_pct',
        'target_price',
        'snapshot_hpp',
        'snapshot_ohc',
        'snapshot_profit',
        'notes',
        'source_sheet',
        'source_recipe_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'yield_qty' => 'decimal:4',
        'ohc_pct' => 'decimal:4',
        'profit_pct' => 'decimal:4',
        'target_price' => 'decimal:2',
        'snapshot_hpp' => 'decimal:2',
        'snapshot_ohc' => 'decimal:2',
        'snapshot_profit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // name_norm adalah pembanding tunggal saat impor dan pencocokan, jadi
        // tidak boleh bergantung pada pemanggil untuk mengisinya dengan benar.
        static::saving(function (self $recipe) {
            $recipe->name_norm = self::normalizeName($recipe->name);
        });
    }

    public static function normalizeName(?string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $name)) ?? '');
    }

    /** @return array<string, string> */
    public static function jenisOptions(): array
    {
        return [
            self::JENIS_UTAMA => 'Menu Utama',
            self::JENIS_SUB => 'Sub-Menu',
        ];
    }

    public function jenisLabel(): string
    {
        return self::jenisOptions()[$this->jenis] ?? (string) $this->jenis;
    }

    public function isSub(): bool
    {
        return $this->jenis === self::JENIS_SUB;
    }

    /** Satuan hasil produksi resep ini, bila dikenali registri satuan. */
    public function yieldUnit(): ?Unit
    {
        return Unit::tryFromAlias($this->yield_unit);
    }

    /**
     * Menu yang angkanya berasal dari Excel, bukan dari rincian bahan.
     *
     * Ditandai supaya laporan bisa membedakan HPP yang benar-benar dihitung
     * dari resep dengan HPP yang sekadar disalin.
     */
    public function usesSnapshot(): bool
    {
        return $this->snapshot_hpp !== null && $this->items()->count() === 0;
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Baris resep lain yang memakai resep ini sebagai sub-menu. */
    public function usedInItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'ref_recipe_id');
    }

    public function scopeUtama(Builder $query): Builder
    {
        return $query->where('jenis', self::JENIS_UTAMA);
    }

    public function scopeSub(Builder $query): Builder
    {
        return $query->where('jenis', self::JENIS_SUB);
    }

    /** Resep yang belum dipetakan ke produk yang dijual. */
    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }
}
